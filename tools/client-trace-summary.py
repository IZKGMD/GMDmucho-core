#!/usr/bin/env python3
"""Turn a MuchoCore client trace into a small endpoint contract."""

from __future__ import annotations

import argparse
import json
from collections import Counter
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True, help="NDJSON trace file.")
    parser.add_argument("--output", required=True, help="Endpoint contract JSON.")
    parser.add_argument(
        "--expected-family",
        default="",
        help="Require every trace entry to belong to this Geometry Dash family.",
    )
    args = parser.parse_args()

    source = Path(args.input)
    if not source.is_file():
        raise SystemExit(f"Input trace does not exist: {source}")

    endpoints = {}
    counts = Counter()
    families = Counter()
    game_versions = Counter()
    binary_versions = Counter()

    for line in source.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line:
            continue

        entry = json.loads(line)
        method = str(entry.get("method", "GET")).upper()
        path = str(entry.get("path", "/"))
        key = (method, path)
        counts[key] += 1

        family = str(entry.get("client_family", "")).strip()
        if family:
            families[family] += 1

        game_version = entry.get("game_version")
        if game_version is not None:
            game_versions[str(game_version)] += 1

        binary_version = entry.get("binary_version")
        if binary_version is not None:
            binary_versions[str(binary_version)] += 1

        current = endpoints.setdefault(key, {
            "method": method,
            "path": path,
            "statuses": set(),
            "query_keys": set(),
            "post_keys": set(),
            "client_families": set(),
        })

        if entry.get("status") is not None:
            current["statuses"].add(int(entry["status"]))

        current["query_keys"].update(
            str(x) for x in entry.get("query_keys", [])
        )
        current["post_keys"].update(
            str(x) for x in entry.get("post_keys", [])
        )
        if family:
            current["client_families"].add(family)

    if args.expected_family:
        mismatched = sorted(
            family for family in families if family != args.expected_family
        )
        if not families:
            raise SystemExit(
                "Trace contains no client_family metadata; "
                "capture it with MuchoCore ClientTrace."
            )
        if mismatched:
            raise SystemExit(
                f"Unexpected client families: {mismatched}; "
                f"expected {args.expected_family}."
            )

    output = []
    for key in sorted(endpoints):
        item = endpoints[key]
        output.append({
            "method": item["method"],
            "path": item["path"],
            "request_count": counts[key],
            "statuses_seen": sorted(item["statuses"]),
            "query_keys": sorted(item["query_keys"]),
            "post_keys": sorted(item["post_keys"]),
            "client_families": sorted(item["client_families"]),
        })

    target = Path(args.output)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(
        json.dumps(
            {
                "generated_by": "tools/client-trace-summary.py",
                "trace_client_families": sorted(families),
                "trace_game_versions": sorted(game_versions),
                "trace_binary_versions": sorted(binary_versions),
                "endpoints": output,
            },
            indent=2,
            ensure_ascii=False,
        )
        + "\n",
        encoding="utf-8",
    )
    print(
        f"Wrote {len(output)} unique endpoints to {target} "
        f"for families {sorted(families) or ['unknown']}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
