#!/usr/bin/env python3
"""Offline loopback HTTP smoke tests for the bounded concurrency runner."""
import importlib.util
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import threading
import time
import unittest

MODULE = Path(__file__).resolve().parents[2] / "tools/performance/concurrent_php8_http.py"
spec = importlib.util.spec_from_file_location("concurrent_php8_http", MODULE)
bench = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bench)


class Stub(BaseHTTPRequestHandler):
    active = 0
    peak = 0
    lock = threading.Lock()

    def do_POST(self):
        length = int(self.headers.get("Content-Length", "0"))
        self.rfile.read(length)
        with type(self).lock:
            type(self).active += 1
            type(self).peak = max(type(self).peak, type(self).active)
        try:
            time.sleep(0.02)
            if self.path.endswith("/getGJUserInfo20.php"):
                body = b"StressRobot"
            elif self.path.endswith("/uploadGJComment21.php"):
                body = b"12345"
            elif self.path.endswith("/uploadGJAccComment20.php"):
                body = b"1"
            elif self.path.endswith("/invalid"):
                body = b"-1"
            else:
                body = b"Synthetic Stress Level 00001"
            self.send_response(200)
            self.send_header("Content-Type", "text/plain")
            self.end_headers()
            self.wfile.write(body)
        finally:
            with type(self).lock:
                type(self).active -= 1

    def log_message(self, *_args):
        pass


class ConcurrencyRunnerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = ThreadingHTTPServer(("127.0.0.1", 0), Stub)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.old_base = bench.BASE
        bench.BASE = "http://127.0.0.1:" + str(cls.server.server_port)

    @classmethod
    def tearDownClass(cls):
        bench.BASE = cls.old_base
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join(timeout=3)

    def test_read_requests_are_valid_and_parallel(self):
        result = bench.run_stage("read", 4, bench.READ_JOBS, 16, 0, 10000)
        self.assertEqual(result["success"], 16)
        self.assertEqual(result["errors"], {})
        self.assertTrue(result["passed"])
        self.assertGreaterEqual(Stub.peak, 2)

    def test_synthetic_writes_are_measured(self):
        job = {"path": "/database/uploadGJComment21.php",
               "form": {"accountID": "1001", "gjp2": "test", "comment": "test"},
               "expected": "positive_id"}
        result = bench.run_stage("write", 2, [job], 8, 0, 10000)
        self.assertEqual(result["success"], 8)
        self.assertTrue(result["passed"])

    def test_gd_error_body_is_not_counted_success(self):
        job = {"path": "/database/invalid", "form": {"test": "1"},
               "expected": "positive_id"}
        result = bench.run_stage("bad", 1, [job], 8, 0, 10000)
        self.assertEqual(result["success"], 0)
        self.assertEqual(result["errors"]["invalid_gd_response"], 8)
        self.assertFalse(result["passed"])

    def test_percentiles(self):
        self.assertEqual(bench.percentile([10, 20, 30, 40], .95), 40)
        self.assertIsNone(bench.percentile([], .95))


if __name__ == "__main__":
    unittest.main()
