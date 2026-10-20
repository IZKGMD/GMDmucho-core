#!/usr/bin/env python3
"""Unit tests for privacy-safe profile aggregation."""
import importlib.util
import io
import unittest
from contextlib import redirect_stdout
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[2] / "tools/performance/profile_summary.py"
spec = importlib.util.spec_from_file_location("profile_summary", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ProfileSummaryTest(unittest.TestCase):
    def test_ignores_unrelated_and_malformed_lines(self):
        records = module.parse([
            "app-1 | NOTICE: PHP message: [MuchoCore Perf] {bad json}\n",
            'app-1 | [MuchoCore Perf] {"route":"getGJLevels21","total_ms":15.2,"cache_hit":true}\n',
            'app-1 | [MuchoCore Perf] {"route":"other","total_ms":1}\n',
            'app-1 | [MuchoCore Perf] {"route":"getGJLevels21","total_ms":"not a number"}\n',
        ])
        self.assertEqual(len(records), 1)
        self.assertEqual(records[0]["total_ms"], 15.2)

    def test_uses_nearest_rank(self):
        self.assertEqual(module.percentile([3, 1, 2, 5, 4], .95), 5)

    def test_prints_cache_hit_miss_groups(self):
        a = [{"total_ms": 10, "cache_hit": True},
             {"total_ms": 20, "cache_hit": False, "sql_levels_ms": 4}]
        with io.StringIO() as buffer, redirect_stdout(buffer):
            module.report("ALL", a)
            output = buffer.getvalue()
        self.assertIn("ALL (n=2)", output)
        self.assertIn("total_ms", output)
        self.assertIn("sql_levels_ms", output)


if __name__ == "__main__":
    unittest.main()
