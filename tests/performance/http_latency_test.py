#!/usr/bin/env python3
"""Local-only regression tests for the bounded latency probe."""

import json
import subprocess
import sys
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from importlib.util import module_from_spec, spec_from_file_location
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "tools/performance/http_latency.py"
spec = spec_from_file_location("http_latency", SCRIPT)
module = module_from_spec(spec)
spec.loader.exec_module(module)


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == "/redirect":
            self.send_response(302)
            self.send_header("Location", "/")
            self.end_headers()
            return
        self.send_response(200)
        self.end_headers()
        self.wfile.write(b"-1" if self.path == "/bad" else b"1")

    def log_message(self, *_):
        pass


class LatencyTest(unittest.TestCase):
    def test_quantiles(self):
        self.assertEqual(module.quantile(list(range(1, 101)), 0.95), 95)
        self.assertEqual(module.quantile([1, 2, 3], 0.99), 3)
        self.assertIsNone(module.quantile([], 0.5))

    def test_local_measurements_and_failures(self):
        httpd = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        server = threading.Thread(target=httpd.serve_forever, daemon=True)
        server.start()
        url = f"http://127.0.0.1:{httpd.server_port}/"
        try:
            result = subprocess.run([
                sys.executable, str(SCRIPT), "--url", url,
                "--warmup", "1", "--count", "6", "--concurrency", "2",
            ], capture_output=True, text=True, check=True)
            report = json.loads(result.stdout)
            self.assertEqual(report["successful"], 6)
            self.assertEqual(report["failures"], 0)
            self.assertGreaterEqual(report["latency_ms"]["p95"], 0)
            self.assertFalse(report["certifies_native_gd_client"])

            error = subprocess.run([
                sys.executable, str(SCRIPT), "--url", url + "bad",
                "--warmup", "0", "--count", "2",
            ], capture_output=True, text=True)
            self.assertEqual(error.returncode, 1)
            self.assertEqual(
                json.loads(error.stdout)["failure_types"], {"gd_rejected": 2}
            )
            redirected = subprocess.run([
                sys.executable, str(SCRIPT), "--url", url + "redirect",
                "--warmup", "0", "--count", "1",
            ], capture_output=True, text=True)
            self.assertEqual(redirected.returncode, 1)
            self.assertEqual(
                json.loads(redirected.stdout)["failure_types"], {"http_302": 1}
            )
        finally:
            httpd.shutdown()
            httpd.server_close()

    def test_remote_and_secrets_rejected(self):
        for args in (
            ["--url", "https://example.com/"],
            ["--url", "http://localhost/path?gjp2=abc"],
        ):
            result = subprocess.run(
                [sys.executable, str(SCRIPT), *args],
                capture_output=True, text=True
            )
            self.assertNotEqual(result.returncode, 0)


if __name__ == "__main__":
    unittest.main()
