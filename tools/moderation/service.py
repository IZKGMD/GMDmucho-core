"""Offline CPU image screening. Private IPC only; no HTTP network listener."""
from pathlib import Path
import argparse
import base64
import hashlib
import io
import json
import math
import os
import re
import socket
import socketserver
import stat
import unicodedata
from http.server import BaseHTTPRequestHandler
import numpy as np
from PIL import Image

SPEC = json.loads(Path(__file__).with_name("model.json").read_text())
MAX_IMAGE = 4 * 1024 * 1024
MAX_BODY = 6 * 1024 * 1024
Image.MAX_IMAGE_PIXELS = 12_000_000


def decision(score):
    if isinstance(score, bool) or not isinstance(score, (int, float)) or not math.isfinite(score) or not 0 <= score <= 1:
        return {"state": "pending", "reason": "local_invalid_response"}
    if score >= .80:
        return {"state": "rejected", "reason": "local_nsfw"}
    if score > .15:
        return {"state": "pending", "reason": "local_uncertain"}
    return {"state": "approved", "reason": "local_passed"}


class Classifier:
    def __init__(self, model, rules=None):
        # Refuse changed weights before constructing an ONNX execution session.
        with Path(model).open("rb") as source:
            if hashlib.file_digest(source, "sha256").hexdigest() != SPEC["sha256"]:
                raise ValueError("Model checksum mismatch")
        import onnxruntime as ort
        ort.disable_telemetry_events()
        options = ort.SessionOptions()
        options.intra_op_num_threads = 2
        options.inter_op_num_threads = 1
        self.session = ort.InferenceSession(str(model), sess_options=options, providers=["CPUExecutionProvider"])
        inputs = self.session.get_inputs()
        outputs = self.session.get_outputs()
        if len(inputs) != 1 or inputs[0].name != "pixel_values" or inputs[0].type != "tensor(float)" or len(outputs) != 1:
            raise ValueError("Unexpected model interface")
        self.rules = []
        if rules:
            entries = json.loads(Path(rules).read_text())
            if not isinstance(entries, list) or len(entries) > 1000:
                raise ValueError("Invalid local text rules")
            for word in entries:
                if not isinstance(word, str) or not word.strip() or len(word) > 200:
                    raise ValueError("Invalid local text rule")
                self.rules.append(re.compile(r"(?<!\w)" + re.escape(unicodedata.normalize("NFKC", word).casefold()) + r"(?!\w)"))

    def score(self, image):
        small = image.resize((224, 224), Image.Resampling.BILINEAR)
        values = np.asarray(small, dtype=np.float32) / 255.
        tensor = np.ascontiguousarray(((values - .5) / .5).transpose(2, 0, 1)[None])
        logits = np.asarray(self.session.run(None, {"pixel_values": tensor})[0], dtype=np.float64)
        if logits.shape != (1, 2) or not np.isfinite(logits).all():
            raise ValueError("Invalid model result")
        logits = logits[0] - logits[0].max()
        probabilities = np.exp(logits)
        return float(probabilities[1] / probabilities.sum())

    @staticmethod
    def views(image):
        yield image
        w, h = image.size
        side = min(w, h)
        if max(w, h) <= 448:
            return
        if max(w, h) > side * 1.5:
            for fraction in (0., .5, 1.):
                x = round((w - side) * fraction)
                y = round((h - side) * fraction)
                yield image.crop((x, y, x + side, y + side))
        else:
            side = max(224, side // 2)
            for x, y in ((0, 0), (w-side, 0), (0, h-side), (w-side, h-side), ((w-side)//2, (h-side)//2)):
                yield image.crop((x, y, x + side, y + side))

    def classify(self, encoded, text):
        if not isinstance(text, str) or len(text.encode("utf-8")) > 2000 or not isinstance(encoded, str):
            raise ValueError("Invalid input")
        normalized = unicodedata.normalize("NFKC", text).casefold()
        if any(rule.search(normalized) for rule in self.rules):
            return {"state": "rejected", "reason": "local_text_rule"}
        if not encoded:
            return {"state": "approved", "reason": "local_text_rules_passed"}
        if len(encoded) > 5592410:
            raise ValueError("Image too large")
        raw = base64.b64decode(encoded, validate=True)
        if len(raw) > MAX_IMAGE or base64.b64encode(raw).decode() != encoded:
            raise ValueError("Invalid image encoding")
        with Image.open(io.BytesIO(raw)) as source:
            w, h = source.size
            if source.format not in ("JPEG", "PNG") or w < 16 or h < 16 or max(w,h) > 8192 or w*h > 12_000_000:
                raise ValueError("Invalid image dimensions")
            # Work on a clean RGB image. Never save uploads or their metadata.
            source.load()
            rgba = source.convert("RGBA")
            background = Image.new("RGBA", rgba.size, (27, 41, 65, 255))
            image = Image.alpha_composite(background, rgba).convert("RGB")
            image.thumbnail((2048, 2048), Image.Resampling.LANCZOS)
            score = max(self.score(view) for view in self.views(image))
        return decision(score)


class Handler(BaseHTTPRequestHandler):
    def setup(self):
        self.request.settimeout(4)
        super().setup()

    def log_message(self, *_):
        pass  # Neither request bodies nor captions enter logs.

    def reply(self, value, status_code=200):
        body = json.dumps({"schema_version": 1, "local": True, **value}, allow_nan=False).encode()
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        if self.path != "/classify" or self.headers.get("Transfer-Encoding"):
            self.reply({"state": "pending", "reason": "local_invalid_request"}, 400)
            return
        try:
            size = int(self.headers.get("Content-Length", "0"))
            if size < 2 or size > MAX_BODY:
                raise ValueError("Invalid body size")
            raw = self.rfile.read(size)
            if len(raw) != size:
                raise ValueError("Incomplete body")
            data = json.loads(raw)
            if not isinstance(data, dict) or set(data) != {"imageData", "text"}:
                raise ValueError("Invalid payload")
            result = self.server.classifier.classify(data["imageData"], data["text"])
            self.reply(result)
        except (ValueError, TypeError, OSError):
            self.reply({"state": "pending", "reason": "local_invalid_request"}, 400)
        except Exception:
            self.reply({"state": "pending", "reason": "local_unavailable"}, 503)


class Server(socketserver.UnixStreamServer):
    request_queue_size = 8  # One inference at a time keeps CPU/RAM bounded.
    def __init__(self, path, classifier):
        self.classifier = classifier
        super().__init__(str(path), Handler)


def serve(path, classifier):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o750)
    if path.exists():
        if not stat.S_ISSOCK(path.lstat().st_mode):
            raise ValueError("Socket path is not a socket")
        probe = socket.socket(socket.AF_UNIX)
        try:
            probe.settimeout(1)
            try:
                probe.connect(str(path))
            except ConnectionRefusedError:
                path.unlink()
            else:
                raise ValueError("Moderation service already running")
        finally:
            probe.close()
    with Server(path, classifier) as server:
        os.chmod(path, 0o660)
        try:
            server.serve_forever()
        finally:
            path.unlink(missing_ok=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--model", type=Path, required=True)
    parser.add_argument("--socket", type=Path, default=Path("/run/mucho-moderation/moderation.sock"))
    parser.add_argument("--text-rules", type=Path)
    args = parser.parse_args()
    serve(args.socket, Classifier(args.model, args.text_rules))
