#!/usr/bin/env python3
"""Sanity check the opt-in PHP7 5v.pl edge without contacting an upstream."""
import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
EDGE = ROOT / "tools/hosting/5v-edge.php"
PROBE = ROOT / "tools/hosting/5v-probe.php"


def cli(path, method, uri, no_extensions=False):
    src = (
        '$_SERVER["REQUEST_METHOD"]=$argv[1];'
        '$_SERVER["REQUEST_URI"]=$argv[2];'
        'require $argv[3];'
    )
    command = ["php"]
    if no_extensions:
        command.append("-n")
    command += ["-r", src, method, uri, str(path)]
    return subprocess.run(command, text=True, capture_output=True)


@unittest.skipUnless(shutil.which("php"), "PHP required")
class Edge5vTest(unittest.TestCase):
    def test_php_syntax_and_diagnostics(self):
        for script in (EDGE, PROBE):
            result = subprocess.run(
                ["php", "-l", str(script)],
                capture_output=True, text=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
        probe = subprocess.run(
            ["php", str(PROBE)], capture_output=True, text=True,
        )
        self.assertEqual(probe.returncode, 0, probe.stderr)
        data = json.loads(probe.stdout)
        self.assertIn("php_version", data)
        self.assertIn("full_muchocore_possible", data)

    def test_health_never_forges_success_without_upstream(self):
        # PHP -n disables cURL, giving an offline test with zero network traffic.
        result = cli(EDGE, "GET", "/health", no_extensions=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, "-1")
        self.assertNotIn("echo '1';", EDGE.read_text(encoding="utf-8"))

    def test_health_rejects_post_without_connecting(self):
        result = cli(EDGE, "POST", "/health")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, "-1")

    def test_administration_and_external_proxy_paths_blocked(self):
        for method, path in (
            ("GET", "/admin"),
            ("POST", "/admin/?page=clientpatcher"),
            ("GET", "/?url=http://127.0.0.1"),
            ("POST", "/database/../admin/index.php"),
            ("GET", "/database/getGJLevels21.php%2Fother"),
            ("DELETE", "/database/getGJLevels21.php"),
        ):
            with self.subTest(method=method, path=path):
                result = cli(EDGE, method, path)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout, "-1")


if __name__ == "__main__":
    unittest.main()
