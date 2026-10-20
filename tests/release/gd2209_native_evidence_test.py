#!/usr/bin/env python3
"""Keep a native route observation distinct from client acceptance."""
import json
import tempfile
import unittest
from importlib.util import module_from_spec, spec_from_file_location
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[2] / "tools/release/gd2209_native_evidence.py"
spec = spec_from_file_location("gd2209_native_evidence", SOURCE)
audit_module = module_from_spec(spec)
spec.loader.exec_module(audit_module)


def contract_manifest():
    return {"contracts": [
        {"id": name, "canonical_path": "/" + name.replace(".", ""),
         "aliases": ["/database/" + name.replace(".", "") + ".php"]}
        for name in audit_module.P0
    ]}


def evidence(name, version="51", game="22", family="2.2", count=30, duration=5):
    return {
        "method": "POST",
        "path": "/a/database/" + name.replace(".", "") + ".php",
        "request_count": count,
        "binary_versions": [version],
        "game_versions": [game],
        "client_families": [family],
        "statuses_seen": [200],
        "server_duration_sample_count": count,
        "server_duration_p95_ms": duration,
    }


class EvidenceAuditTest(unittest.TestCase):
    def test_six_exact_version_routes_still_do_not_certify_native_client(self):
        data = {"endpoints": [evidence(name) for name in audit_module.P0]}
        result = audit_module.analyze(contract_manifest(), data)
        self.assertEqual(result["p0_route_observed"], 6)
        self.assertEqual(result["p0_route_missing"], 0)
        self.assertTrue(all(item["has_preliminary_timing_samples"]
                            for item in result["p0"]))
        self.assertFalse(result["native_client_acceptance_certified"])
        self.assertFalse(result["stable_release_certified"])

    def test_one_global_51_does_not_certify_other_routes(self):
        data = {"trace_binary_versions": ["47", "51"], "endpoints": [
            evidence("levels.search", version="47"),
            evidence("account.login"),
        ]}
        result = audit_module.analyze(contract_manifest(), data)
        self.assertEqual(result["p0_route_observed"], 1)
        lookup = {item["id"]: item for item in result["p0"]}
        self.assertFalse(lookup["levels.search"]["strict_2209_observed"])
        self.assertEqual(lookup["levels.search"]["request_count"], 0)
        self.assertEqual(lookup["levels.search"]["matched_paths"],
                         ["/a/database/levelssearch.php"])
        self.assertTrue(lookup["account.login"]["strict_2209_observed"])

    def test_mixed_version_at_same_endpoint_is_not_exact_evidence(self):
        row = evidence("levels.upload")
        row["binary_versions"] = ["47", "51"]
        data = {"endpoints": [row, evidence("comments.read", game="21"),
                              evidence("levels.download", family="unknown")]}
        report = audit_module.analyze(contract_manifest(), data)
        self.assertEqual(report["p0_route_observed"], 0)
        self.assertEqual(report["p0_route_missing"], 6)

    def test_alias_prefix_normalization(self):
        self.assertEqual(
            audit_module.normalize("/a/database/getGJLevels21.php"),
            audit_module.normalize("/getgjlevels21")
        )
        self.assertEqual(
            audit_module.normalize("/api/accounts/database/getGJScores20.php?x=1"),
            "/getgjscores20"
        )


if __name__ == "__main__":
    unittest.main()
