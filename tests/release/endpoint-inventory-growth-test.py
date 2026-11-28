#!/usr/bin/env python3
"""Prevent introducing unverified game endpoints after v1.1.1.

The release baseline acknowledges existing technical debt; it does not certify
any previously inventoried route. New routes require complete evidence before
merging. This test is intentionally independent of the application router.
"""
from __future__ import annotations

import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
BASELINE = ROOT / "tests/release/v111-endpoint-baseline.json"
REGISTRY = ROOT / "tests/protocol/endpoint-contracts.json"
DIMENSIONS = (
    "request_contract",
    "wire_contract",
    "auth_contract",
    "negative_cases",
    "db_integrity",
    "concurrency",
    "performance_budget",
    "real_client",
)


def main() -> int:
    baseline = json.loads(BASELINE.read_text(encoding="utf-8"))
    registry = json.loads(REGISTRY.read_text(encoding="utf-8"))
    known = baseline.get("known_paths", [])
    previous_inventory = baseline.get("inventory_only_paths", [])
    contracts = registry.get("contracts", [])
    errors: list[str] = []

    if baseline.get("schema_version") != 1 or len(known) != 166 or len(previous_inventory) != 160:
        errors.append("The fixed v1.1.1 baseline has changed; manual review required")
    if len(known) != len(set(known)) or len(previous_inventory) != len(set(previous_inventory)):
        errors.append("Duplicate path in the v1.1.1 baseline")
    if not set(previous_inventory).issubset(set(known)):
        errors.append("Baseline inventory-only paths are not in the known path set")

    entries = [entry for entry in contracts if isinstance(entry, dict)]
    if len(entries) != len(contracts):
        errors.append("Registry contains a non-object contract")
    paths = [entry.get("canonical_path") for entry in entries]
    if len(paths) != len(set(paths)):
        errors.append("Duplicate canonical path in current registry")

    current = set(paths)
    removed = sorted(set(known) - current)
    if removed:
        errors.append(
            "Routes existing in v1.1.1 were removed without protocol review: "
            + ", ".join(removed)
        )

    added = [entry for entry in entries if entry.get("canonical_path") not in set(known)]
    for entry in added:
        path = str(entry.get("canonical_path"))
        if entry.get("inventory_only") is not False:
            errors.append(path + ": new routes cannot be inventory_only")
        if entry.get("release_gate") is not True:
            errors.append(path + ": new route must have release_gate=true")
        for dimension in DIMENSIONS:
            evidence = entry.get(dimension)
            if not isinstance(evidence, dict) or evidence.get("status") not in ("covered", "n/a"):
                errors.append(path + ": " + dimension + " is not covered")
            elif evidence.get("status") == "covered" and not evidence.get("evidence"):
                errors.append(path + ": missing evidence for " + dimension)
        client = entry.get("real_client", {})
        if not isinstance(client, dict) or client.get("status") != "covered":
            errors.append(path + ": native real-client evidence is mandatory")
        budget = entry.get("performance_budget", {})
        if (not isinstance(budget, dict) or budget.get("status") != "covered"
            or not isinstance(budget.get("p95_ms"), (int, float))
            or isinstance(budget.get("p95_ms"), bool)
            or budget["p95_ms"] <= 0):
            errors.append(path + ": measured p95 performance budget is mandatory")

    if errors:
        for msg in errors:
            print("FAIL endpoint-growth-gate: " + msg, file=sys.stderr)
        return 1

    outstanding = sum(bool(entry.get("inventory_only")) for entry in entries)
    certified = sum(entry.get("release_gate") is True for entry in entries)
    print(
        f"PASS endpoint-growth-gate: total={len(entries)} new={len(added)} "
        f"inventory_only={outstanding} release_gated={certified}. "
        "Existing inventory debt is NOT certified."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
