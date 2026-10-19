#!/usr/bin/env python3
"""Read-only P0 endpoint readiness audit for MuchoCore 1.1.0.

A fixture matching an endpoint proves only route observation, NOT native client
acceptance or correct response wire format. Never use this to sign off a release.
"""

import argparse
import json
from pathlib import Path

P0 = (
    "account.login", "levels.search", "levels.upload",
    "levels.download", "users.leaderboard", "comments.read",
)
DIMENSIONS = (
    "request_contract", "wire_contract", "auth_contract",
    "negative_cases", "db_integrity", "concurrency",
    "performance_budget", "real_client",
)


def read_json(path):
    with path.open(encoding="utf-8") as stream:
        return json.load(stream)


def audit(root):
    root = Path(root)
    manifest = read_json(root / "tests/protocol/endpoint-contracts.json")
    contracts = manifest.get("contracts", [])
    by_id = {
        entry["id"]: entry for entry in contracts
        if isinstance(entry, dict) and isinstance(entry.get("id"), str)
    }
    fixtures = []
    for path in sorted((root / "tests/client-fixtures").glob("*/endpoints.json")):
        data = read_json(path)
        for item in data.get("endpoints", []):
            if isinstance(item, dict):
                fixtures.append((path.relative_to(root).as_posix(), data, item))

    results = []
    for name in P0:
        contract = by_id.get(name)
        if contract is None:
            results.append({
                "id": name, "missing": True, "ready": False,
                "blockers": ["contract missing"], "observed_routes": [],
            })
            continue

        blockers = []
        statuses = {}
        for dim in DIMENSIONS:
            entry = contract.get(dim) or {}
            status = entry.get("status", "missing")
            statuses[dim] = status
            if status not in ("covered", "n/a"):
                blockers.append(dim + ": " + status)
            evidence = entry.get("evidence", [])
            if not isinstance(evidence, list):
                blockers.append(dim + ": evidence must be a list")
                continue
            for path in evidence:
                if (
                    not isinstance(path, str) or path.startswith("/")
                    or ".." in Path(path).parts or not (root / path).is_file()
                ):
                    blockers.append(dim + ": missing/unsafe evidence path")
            if status == "covered" and not evidence:
                blockers.append(dim + ": no evidence")

        if contract.get("release_gate") is not True or contract.get("inventory_only") is True:
            blockers.append("not release-gated")

        paths = {p.lower() for p in contract.get("aliases", []) if isinstance(p, str)}
        paths.add(str(contract.get("canonical_path", "")).lower())
        observed = []
        for filename, registry, item in fixtures:
            if str(item.get("path", "")).lower() not in paths:
                continue
            observed.append({
                "fixture": filename,
                "method": item.get("method"),
                "request_count": item.get("request_count", 0),
                "client_families": item.get(
                    "client_families", registry.get("trace_client_families", [])
                ),
                "binary_versions": registry.get("trace_binary_versions", []),
            })

        if not observed:
            blockers.append("no matching real-client route observation")
        if not any(
            "51" in [str(v) for v in obs["binary_versions"]]
            for obs in observed
        ):
            blockers.append("no GD 2.209 (binaryVersion 51) route observation")
        if statuses["performance_budget"] != "covered":
            blockers.append("p95 measurement missing")
        if statuses["real_client"] != "covered":
            blockers.append("native client acceptance not certified")

        results.append({
            "id": name, "missing": False, "ready": not blockers,
            "statuses": statuses, "observed_routes": observed,
            "blockers": list(dict.fromkeys(blockers)),
        })

    return {
        "target_release": "1.1.0", "scope": "P0 diagnostic only",
        "note": "Route observations alone do not certify wire format or native acceptance.",
        "p0_count": len(P0),
        "p0_ready": sum(bool(item["ready"]) for item in results),
        "p0_blocked": sum(not item["ready"] for item in results),
        "p0": results,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--root", type=Path, default=Path(__file__).resolve().parents[2]
    )
    parser.add_argument("--json", action="store_true", help="machine-readable report")
    parser.add_argument("--strict", action="store_true", help="exit nonzero if P0 blocked")
    args = parser.parse_args()
    try:
        report = audit(args.root)
    except (OSError, ValueError, TypeError) as exc:
        parser.exit(2, f"P0 audit failed: {exc}\n")

    if args.json:
        print(json.dumps(report, indent=2, ensure_ascii=False))
    else:
        print(
            f"MuchoCore {report['target_release']} P0: "
            f"{report['p0_ready']}/{report['p0_count']} ready"
        )
        for item in report["p0"]:
            print(
                f"  {'READY' if item['ready'] else 'BLOCKED'} {item['id']}"
                f" (route observations: {len(item['observed_routes'])})"
            )
            for reason in item["blockers"]:
                print(f"    - {reason}")
        print(
            "Diagnostic only; stable release gate remains "
            "tests/release/v110-endpoint-gate.php"
        )
    return 1 if args.strict and report["p0_blocked"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
