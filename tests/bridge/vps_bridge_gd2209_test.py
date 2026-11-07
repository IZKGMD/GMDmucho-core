#!/usr/bin/env python3
"""Local contract tests; never contact a VPS or the internet."""
import importlib.util
import pathlib
import unittest
from unittest import mock

PATH = pathlib.Path(__file__).resolve().parents[2] / "tools" / "bridge" / "vps-bridge-gd2209.py"
SPEC = importlib.util.spec_from_file_location("vps_bridge_gd2209", PATH)
assert SPEC and SPEC.loader
bridge = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(bridge)


class FakeResponse:
    status = 200

    def __init__(self, body):
        self.body = body

    def read(self, limit):
        return self.body[:limit]

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, tb):
        return False


class FakeOpener:
    def __init__(self, response):
        self.response = response
        self.last_request = None

    def open(self, req, timeout):
        self.last_request = req
        assert timeout == 6
        return self.response


class BridgeTests(unittest.TestCase):
    def call_with(self, body):
        opener = FakeOpener(FakeResponse(body))
        with mock.patch.object(bridge.urllib.request, "build_opener", return_value=opener):
            ok = bridge.request(
                "test", "/a/getGJLevels21.php",
                {"gameVersion": "22", "binaryVersion": "51", "str": "zz-no-such"},
            )
        return ok, opener.last_request

    def test_gd_no_results_is_accepted(self):
        ok, req = self.call_with(b"-1")
        self.assertTrue(ok)
        self.assertEqual(req.get_method(), "POST")
        self.assertIn(b"binaryVersion=51", req.data)
        self.assertEqual(req.full_url, "http://127.0.0.1:18080/a/getGJLevels21.php")

    def test_html_error_page_is_rejected(self):
        ok, _ = self.call_with(b"<!DOCTYPE HTML><html>login required</html>")
        self.assertFalse(ok)

    def test_json_is_rejected(self):
        ok, _ = self.call_with(b'{"error":"not geometry dash"}')
        self.assertFalse(ok)

    def test_empty_reply_is_rejected(self):
        ok, _ = self.call_with(b"")
        self.assertFalse(ok)

    def test_only_two_unauthed_searches(self):
        seen = []
        def capture(name, path, fields):
            seen.append((path, fields))
            return True
        with mock.patch.object(bridge, "request", side_effect=capture):
            self.assertEqual(bridge.main(), 0)
        self.assertEqual([x[0] for x in seen], [
            "/a/getGJLevels21.php",
            "/a/getGJUsers20.php",
        ])
        for _, fields in seen:
            self.assertEqual(fields["gameVersion"], "22")
            self.assertEqual(fields["binaryVersion"], "51")
            self.assertNotIn("gjp2", fields)
            self.assertNotIn("password", fields)


if __name__ == "__main__":
    unittest.main()
