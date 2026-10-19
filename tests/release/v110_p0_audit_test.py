#!/usr/bin/env python3
"""Checks that P0 diagnostics never mistake a route trace for certification."""

import json
import tempfile
import unittest
from importlib.util import module_from_spec, spec_from_file_location
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[2] / "tools/release/v110_p0_audit.py"
spec = spec_from_file_location("v110_p0_audit", SCRIPT)
module = module_from_spec(spec)
spec.loader.exec_module(module)


class AuditTest(unittest.TestCase):
    def test_uncertified_trace_cannot_pass(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "tests/protocol").mkdir(parents=True)
            (root / "tests/client-fixtures/2.2").mkdir(parents=True)
            contracts = []
            for name in module.P0:
                entry = {
                    "id": name,
                    "canonical_path": "/" + name,
                    "aliases": ["/database/" + name + ".php"],
                    "release_gate": False,
                    "inventory_only": False,
                }
                for dimension in module.DIMENSIONS:
                    entry[dimension] = {"status": "pending", "evidence": []}
                contracts.append(entry)
            (root / "tests/protocol/endpoint-contracts.json").write_text(
                json.dumps({"contracts": contracts}), encoding="utf-8"
            )
            (root / "tests/client-fixtures/2.2/endpoints.json").write_text(
                json.dumps({
                    "trace_binary_versions": ["47"],
                    "endpoints": [{
                        "path": "/database/levels.search.php",
                        "method": "POST", "request_count": 1,
                    }],
                }), encoding="utf-8"
            )

            result = module.audit(root)
            self.assertEqual(result["p0_ready"], 0)
            self.assertEqual(result["p0_blocked"], 6)
            search = next(item for item in result["p0"] if item["id"] == "levels.search")
            self.assertEqual(len(search["observed_routes"]), 1)
            self.assertIn(
                "no GD 2.209 (binaryVersion 51) route observation",
                search["blockers"],
            )
            self.assertIn("p95 measurement missing", search["blockers"])
            self.assertIn("native client acceptance not certified", search["blockers"])

    def test_missing_p0_is_reported(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "tests/protocol").mkdir(parents=True)
            (root / "tests/protocol/endpoint-contracts.json").write_text(
                json.dumps({"contracts": []}), encoding="utf-8"
            )
            result = module.audit(root)
            self.assertEqual(result["p0_count"], 6)
            self.assertTrue(all(item["missing"] for item in result["p0"]))


if __name__ == "__main__":
    unittest.main()
