#!/usr/bin/env python3
"""Tests for MuchoCore's non-destructive local three-phase performance probe."""
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[2] / "tools/performance/vps_ab.py"
spec = importlib.util.spec_from_file_location("vps_ab", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class VpsAbTest(unittest.TestCase):
    def test_host_validation_rejects_remote_urls_or_injection(self):
        self.assertTrue(module.valid_host("muchogdps.space"))
        for name in (
            "", "https://muchogdps.space", "muchogdps.space:80",
            "a..b", "host\nX-Injected: 1", "host with spaces",
            "a" * 64 + ".example", "test/../admin",
        ):
            self.assertFalse(module.valid_host(name), name)

    def test_local_only_payload_and_bounded_workload(self):
        self.assertEqual(module.URL, "http://127.0.0.1/database/getGJLevels21.php")
        self.assertEqual(
            module.POST,
            b"type=0&page=0&gameVersion=22&binaryVersion=51",
        )
        self.assertNotIn(b"gjp=", module.POST)

    def test_speedup_comparison_uses_both_baselines(self):
        def sample(p50, p95, p99):
            return {"latency_ms": {"p50": p50, "p95": p95, "p99": p99}}
        result = module.comparison(
            sample(20, 40, 60), sample(15, 30, 40), sample(22, 44, 70)
        )
        self.assertEqual(result["p50"]["baseline_average_ms"], 21)
        self.assertEqual(result["p50"]["relative_change_percent"], -28.57)
        self.assertEqual(result["p95"]["observed_speedup_x"], 1.4)

    def test_dry_run_requires_no_docker(self):
        result = subprocess.run(
            [sys.executable, str(SCRIPT), "--host-header", "muchogdps.space", "--dry-run"],
            capture_output=True, text=True,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("normal -> FAST=1 + FILE_CACHE=1 -> normal", result.stdout)

    def test_production_run_requires_explicit_restarts_permission(self):
        result = subprocess.run(
            [sys.executable, str(SCRIPT), "--host-header", "muchogdps.space"],
            capture_output=True, text=True,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("--confirm-app-restarts", result.stderr)

    def test_switch_sets_flags_without_touching_worker_or_db(self):
        calls = []
        def fake_compose(*args, env=None, capture=False):
            calls.append((args, env))
            if args[:3] == ("exec", "-T", "app"):
                return "1" if env is None else ""
            return ""

        with patch.object(module, "compose", side_effect=fake_compose):
            with patch.object(module, "read_flag", side_effect=lambda k: "0" if k == "MUCHO_PERF_PROFILE" else "1"):
                module.switch(experimental=True)
        cmd, env = calls[0]
        self.assertEqual(cmd, ("up", "-d", "--no-deps", "--force-recreate", "app"))
        self.assertEqual(env["MUCHO_FAST_LEVEL_BOOT"], "1")
        self.assertEqual(env["MUCHO_FILE_CACHE_EXPERIMENT"], "1")
        self.assertEqual(env["MUCHO_PERF_PROFILE"], "0")


if __name__ == "__main__":
    unittest.main()
