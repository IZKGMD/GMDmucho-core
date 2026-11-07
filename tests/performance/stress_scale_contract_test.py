#!/usr/bin/env python3
"""Pure contract tests for the bounded localhost scale workload."""
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
PATH = ROOT / "tools" / "performance" / "stress_scale.py"
spec = importlib.util.spec_from_file_location("muchocore_stress_scale", PATH)
assert spec and spec.loader
stress = importlib.util.module_from_spec(spec)
spec.loader.exec_module(stress)


class ScaleStressContract(unittest.TestCase):
    def test_never_targets_external_servers(self):
        self.assertEqual(stress.BASE, "http://127.0.0.1:18080")
        self.assertGreaterEqual(stress.INTERVAL_SECONDS, .3)

    def test_four_distinct_real_gd_routes(self):
        for levels in (1000, 10000):
            cases = stress.scenarios(levels, 0)
            self.assertEqual([name for name, *_ in cases],
                             ["gd_latest", "gd_id", "gd_download", "gd_profile"])
            _, _, ids, expected = cases[1]
            self.assertEqual(ids["str"], str(levels))
            self.assertIn(f"{levels:05d}".encode(), expected)
            _, _, download, expected = cases[2]
            self.assertEqual(download["levelID"], str(levels))
            self.assertEqual(download["inc"], "0")
            self.assertEqual(download["extras"], "1")
            self.assertEqual(cases[3][2]["targetAccountID"], "1")
            for _, _, payload, _ in cases:
                self.assertEqual(payload["gameVersion"], "22")
                self.assertEqual(payload["binaryVersion"], "51")
                self.assertNotIn("gjp", payload)
                self.assertNotIn("gjp2", payload)

    def test_ids_rotate_inside_synthetic_fixture(self):
        for levels in (1000, 10000):
            for step in range(0, 100):
                case = stress.scenarios(levels, step)[1]
                target = int(case[2]["str"])
                self.assertTrue(levels - 20 <= target <= levels)
                self.assertIn(str(target).encode(), case[3])

    def test_percentiles(self):
        self.assertIsNone(stress.quantile([], .95))
        self.assertEqual(stress.quantile([20, 10, 40, 30], .5), 20)
        self.assertEqual(stress.quantile([20, 10, 40, 30], .95), 40)


if __name__ == "__main__":
    unittest.main()
