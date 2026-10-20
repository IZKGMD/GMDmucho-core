#!/usr/bin/env python3
"""Read-only GD 2.209 native P0 route coverage from a sanitized trace summary.

This tool NEVER certifies client acceptance or changes endpoint release status.
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from urllib.parse import urlsplit

P0 = (
    "account.login", "levels.search", "levels.upload",
    "levels.download", "users.leaderboard", "comments.read",
)


def normalize(path: str) -> str:
    """Match the transport prefixes and .php normalization used by Router."""
    raw = urlsplit(path).path
    raw = re.sub(r"/+", "/", raw)
    if not raw.startswith("/"):
        raw = "/" + raw
    raw = raw.lower()
    while True:
        replaced = re.sub(
            r"^/(database|accounts|api|a)(?=/|$)", "", raw, count=1
        )
        if replaced == raw:
            break
        raw = replaced or "/"
    raw = raw.rstrip("/") or "/"
    return re.sub(r"\.php$", "", raw)


def read_json(path: Path) -> dict:
    with path.open(encoding="utf-8") as stream:
        value = json.load(stream)
    if not isinstance(value, dict):
        raise ValueError(f"Expected a JSON object: {path}")
    return value


def analyze(manifest: dict, summary: dict) -> dict:
    contracts = {
        item.get("id"): item
        for item in manifest.get("contracts", [])
        if isinstance(item, dict) and isinstance(item.get("id"), str)
    }
    trace_rows = summary.get("endpoints", [])
    if not isinstance(trace_rows, list):
        raise ValueError("endpoints must be a list")

    results = []
    for name in P0:
        contract = contracts.get(name)
        aliases = []
        if contract is not None:
            aliases = [contract.get("canonical_path", "")]
            aliases += contract.get("aliases", [])
        expected = {normalize(p) for p in aliases if isinstance(p, str) and p}
        matches = []
        for row in trace_rows:
            if not isinstance(row, dict) or not isinstance(row.get("path"), str):
                continue
            if normalize(row["path"]) not in expected:
                continue
            matches.append(row)
        exact = [
            row for row in matches
            if {str(v) for v in row.get("binary_versions", [])} == {"51"}
            and {str(v) for v in row.get("game_versions", [])} == {"22"}
            and {str(v) for v in row.get("client_families", [])} == {"2.2"}
            and str(row.get("method", "")).upper() == "POST"
            and isinstance(row.get("request_count"), int)
            and row["request_count"] > 0
        ]
        observations = []
        for row in exact:
            count = row.get("server_duration_sample_count", 0)
            p95 = row.get("server_duration_p95_ms")
            usable_duration = (
                isinstance(count, int) and not isinstance(count, bool)
                and count >= 30
                and isinstance(p95, (int, float))
                and not isinstance(p95, bool) and p95 >= 0
            )
            observations.append({
                "path": row["path"],
                "request_count": row["request_count"],
                "statuses_seen": row.get("statuses_seen", []),
                "server_duration_sample_count": count,
                "server_duration_p95_ms": p95,
                "at_least_30_valid_durations": usable_duration,
            })
        results.append({
            "id": name,
            "matched_paths": sorted({str(r["path"]) for r in matches}),
            "strict_2209_observed": bool(exact),
            "request_count": sum(x["request_count"] for x in observations),
            "has_preliminary_timing_samples": any(
                x["at_least_30_valid_durations"] for x in observations
            ),
            "observations": observations,
        })

    observed = sum(int(row["strict_2209_observed"]) for row in results)
    return {
        "target": "GD 2.209 gameVersion=22 binaryVersion=51",
        "scope": "observed POST routes only; not client acceptance",
        "p0_route_observed": observed,
        "p0_route_missing": len(P0) - observed,
        "native_client_acceptance_certified": False,
        "stable_release_certified": False,
        "p0": results,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--summary", required=True, type=Path,
                        help="Sanitized JSON created with --binary-version 51 --game-version 22.")
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--json", action="store_true")
    parser.add_argument("--fail-on-missing", action="store_true",
                        help="Exit 1 when one or more P0 routes lacks exact-version observations.")
    args = parser.parse_args()
    try:
        manifest = read_json(args.root / "tests/protocol/endpoint-contracts.json")
        summary = read_json(args.summary)
        report = analyze(manifest, summary)
    except (OSError, ValueError, TypeError) as exc:
        parser.exit(2, f"Capture audit failed: {exc}\n")
    if args.json:
        print(json.dumps(report, indent=2, ensure_ascii=False))
    else:
        print(f"GD 2.209 exact-version P0 observations: "
              f"{report['p0_route_observed']}/{len(P0)}")
        for row in report["p0"]:
            label = "OBSERVED" if row["strict_2209_observed"] else "MISSING"
            timing = "30+ samples" if row["has_preliminary_timing_samples"] else "timing unverified"
            print(f"  {label} {row['id']}: "
                  f"{row['request_count']} request(s), {timing}")
        print("DIAGNOSTIC ONLY: 200 OK / request counts / p95 do not "
              "prove GD client acceptance or enable stable release.")
    return 1 if args.fail_on_missing and report["p0_route_missing"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
