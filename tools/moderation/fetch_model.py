"""Download only during setup/build. The inference service never downloads files."""
from pathlib import Path
import argparse
import hashlib
import json
import os
import urllib.request

SPEC = json.loads(Path(__file__).with_name("model.json").read_text())

def fetch(directory):
    directory.mkdir(parents=True, exist_ok=True)
    target = directory / "model.onnx"
    if target.is_file():
        with target.open("rb") as cached:
            if hashlib.file_digest(cached, "sha256").hexdigest() == SPEC["sha256"]:
                return target
    url = f'https://huggingface.co/{SPEC["repository"]}/resolve/{SPEC["revision"]}/{SPEC["file"]}'
    temporary = directory / "model.part"
    digest = hashlib.sha256()
    try:
        with urllib.request.urlopen(url, timeout=60) as response, temporary.open("wb") as out:
            total = 0
            while chunk := response.read(1024 * 1024):
                total += len(chunk)
                if total > SPEC["size_bytes"]:
                    raise ValueError("Unexpected model size")
                out.write(chunk)
                digest.update(chunk)
        if total != SPEC["size_bytes"] or digest.hexdigest() != SPEC["sha256"]:
            raise ValueError("Model checksum mismatch")
        os.replace(temporary, target)
    finally:
        temporary.unlink(missing_ok=True)
    return target

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--out", type=Path, required=True)
    args = parser.parse_args()
    print(f"Verified local model: {fetch(args.out)}")
