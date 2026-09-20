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
    args = parser.parse_args()

    source = Path(args.input)
    if not source.is_file():
        raise SystemExit(f"Input trace does not exist: {source}")

    endpoints = {}
    counts = Counter()

    for line in source.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line:
            continue
        entry = json.loads(line)
        method = str(entry.get("method", "GET")).upper()
        path = str(entry.get("path", "/"))
        key = (method, path)
        counts[key] += 1
        current = endpoints.setdefault(key, {
            "method": method,
            "path": path,
            "statuses": set(),
            "query_keys": set(),
            "post_keys": set(),
        })
        if entry.get("status") is not None:
            current["statuses"].add(int(entry["status"]))
        current["query_keys"].update(str(x) for x in entry.get("query_keys", []))
        current["post_keys"].update(str(x) for x in entry.get("post_keys", []))

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
        })

    target = Path(args.output)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(
        json.dumps({
            "generated_by": "tools/client-trace-summary.py",
            "endpoints": output,
        }, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    print(f"Wrote {len(output)} unique endpoints to {target}")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
