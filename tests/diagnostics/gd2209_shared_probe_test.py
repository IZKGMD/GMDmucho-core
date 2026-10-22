#!/usr/bin/env python3
"""Offline synthetic tests for public GD 2.209 route diagnosis."""
import base64
import gzip
import importlib.util
import pathlib
import unittest
from unittest.mock import patch

HERE = pathlib.Path(__file__).resolve()
spec = importlib.util.spec_from_file_location(
    "shared_probe", HERE.parents[2] / "tools/diagnostics/gd2209-shared-probe.py"
)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
ORIGIN = "https://gdps.example.com"
FEED = base64.urlsafe_b64encode(gzip.compress(b"4,1|1:1;3:SGVsbG8="))


class NativeNewsProbeTest(unittest.TestCase):
    def test_valid_origin(self):
        self.assertEqual(mod.normalized_origin("https://GDPS.Example.com/"), ORIGIN)
        for bad in [
            "http://gdps.example.com", "https://gdps.example.com/mc/",
            "https://user:pass@gdps.example.com", "https://gdps.example.com:8443",
            "https://gdps.example.com/?a=b",
        ]:
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                mod.normalized_origin(bad)

    def test_all_responses_valid(self):
        expected = {
            ("/mc/health", "GET"): (200, b"1"),
            ("/a/getCustomContentURL.php", "POST"): (
                200, b"https://gdps.example.com/gd-content"
            ),
            ("/gd-content/news/news_version.txt", "GET"): (200, b"1000001"),
            ("/gd-content/news/news.dat", "GET"): (200, FEED),
        }
        def fake(origin, path, method="GET", timeout=10):
            self.assertEqual(origin, ORIGIN)
            return expected[(path, method)]
        with patch.object(mod, "fetch", side_effect=fake):
            data = mod.check_news(ORIGIN)
        self.assertEqual(data["status"], "news_routes_ok_not_client_certified")
        self.assertEqual(len(data["checks"]), 4)
        self.assertTrue(all(v["ok"] for v in data["checks"].values()))

    def test_missing_news_rewrites_are_detected(self):
        def fake(origin, path, method="GET", timeout=10):
            if path.endswith("news.dat") or path.endswith(".txt"):
                return 404, b"<html>Not Found</html>"
            return 200, b"1" if path == "/mc/health" else (
                origin + "/gd-content"
            ).encode()
        with patch.object(mod, "fetch", side_effect=fake):
            data = mod.check_news(ORIGIN)
        self.assertEqual(data["status"], "news_or_routing_configuration_gap")
        self.assertIn(".txt", data["checks"]["news_version"]["message"])
        self.assertIn(".dat", data["checks"]["news_data"]["message"])

    def test_feature_disabled_without_false_positive(self):
        with patch.object(mod, "fetch", return_value=(200, b"-1")):
            data = mod.check_news(ORIGIN)
        self.assertFalse(data["checks"]["news_version"]["ok"])
        self.assertFalse(data["checks"]["news_data"]["ok"])
        self.assertFalse(data["checks"]["content_url"]["ok"])

    def test_rewrite_docs_cover_exact_news_requests(self):
        readme = (HERE.parents[2] / "patchers/shared-hosting/README.md").read_text()
        self.assertIn(r"^gd-content/news/news_version\.txt$", readme)
        self.assertIn(r"^gd-content/news/news\.dat$", readme)
        self.assertIn("enable_native_news", readme)
        self.assertIn("enable_registration=false", readme)


if __name__ == "__main__":
    unittest.main()
