#!/usr/bin/env python3
"""Regression tests for endpoint-scoped 2.209 trace evidence and timing stats."""

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
TOOL = ROOT / "tools/client/client-trace-summary.py"


class TraceSummaryTest(unittest.TestCase):
    def test_mixed_versions_are_separated_per_endpoint(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            raw = root / "trace.ndjson"
            output = root / "endpoints.json"
            rows = [
                {
                    "method": "POST",
                    "path": "/database/getGJLevels21.php",
                    "game_version": 22,
                    "binary_version": 47,
                    "client_family": "2.2",
                    "status": 200,
                    "duration_ms": 11.0,
                },
                {
                    "method": "POST",
                    "path": "/database/getGJScores20.php",
                    "game_version": 22,
                    "binary_version": 51,
                    "client_family": "2.2",
                    "status": 200,
                    "duration_ms": 20.0,
                },
            ]
            for duration in range(1, 31):
                rows.append({
                    "method": "POST",
                    "path": "/database/getGJLevels21.php",
                    "game_version": 22,
                    "binary_version": 51,
                    "client_family": "2.2",
                    "status": 200,
                    "duration_ms": float(duration),
                    "post_keys": ["gameVersion", "binaryVersion", "gjp2"],
                })
            raw.write_text(
                "".join(json.dumps(row) + "\n" for row in rows),
                encoding="utf-8",
            )
            subprocess.run([
                sys.executable, str(TOOL),
                "--input", str(raw),
                "--output", str(output),
                "--expected-family", "2.2",
                "--min-binary", "51",
            ], check=True, capture_output=True, text=True)

            result = json.loads(output.read_text(encoding="utf-8"))
            self.assertEqual(result["trace_binary_versions"], ["51"])
            by_path = {e["path"]: e for e in result["endpoints"]}
            levels = by_path["/database/getGJLevels21.php"]
            scores = by_path["/database/getGJScores20.php"]
            self.assertEqual(levels["binary_versions"], ["51"])
            self.assertEqual(levels["game_versions"], ["22"])
            self.assertEqual(levels["server_duration_sample_count"], 30)
            self.assertEqual(levels["server_duration_p95_ms"], 29.0)
            self.assertEqual(scores["server_duration_sample_count"], 1)
            self.assertEqual(scores["server_duration_p95_ms"], 20.0)

            subprocess.run([
                sys.executable, str(TOOL),
                "--input", str(raw),
                "--output", str(output),
            ], check=True, capture_output=True, text=True)
            result = json.loads(output.read_text(encoding="utf-8"))
            levels = next(
                e for e in result["endpoints"]
                if e["path"] == "/database/getGJLevels21.php"
            )
            self.assertEqual(levels["binary_versions"], ["47", "51"])
            self.assertEqual(levels["server_duration_sample_count"], 31)


if __name__ == "__main__":
    unittest.main()
