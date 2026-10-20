#!/usr/bin/env python3
"""Summarize opt-in MuchoCore GD level-search timings from Docker logs.

Usage:
  docker compose logs --since 5m app 2>&1 | python3 tools/performance/profile_summary.py

No input values or secrets are recorded by the corresponding PHP profiler.
"""
import json
import math
import sys

MARKER = "[MuchoCore Perf] "
FIELDS = (
    "total_ms", "bootstrap_ms", "protection_ms", "app_init_ms",
    "handler_ms", "db_connect_ms", "cache_read_ms",
    "search_ms", "sql_levels_ms", "sql_count_ms", "sql_index_probe_ms",
    "encode_ms", "cache_write_ms",
)


def parse(lines):
    entries = []
    for line in lines:
        if MARKER not in line:
            continue
        payload = line.split(MARKER, 1)[1].strip()
        try:
            obj = json.loads(payload)
        except (ValueError, TypeError):
            continue
        if not isinstance(obj, dict) or obj.get("route") != "getGJLevels21":
            continue
        if not isinstance(obj.get("total_ms"), (int, float)):
            continue
        entries.append(obj)
    return entries


def percentile(values, proportion):
    values = sorted(values)
    return values[max(0, math.ceil(proportion * len(values)) - 1)]


def report(name, samples):
    print(f"{name} (n={len(samples)})")
    if not samples:
        print("  no samples")
        return
    for field in FIELDS:
        numbers = [
            float(x[field]) for x in samples
            if isinstance(x.get(field), (int, float))
            and not isinstance(x.get(field), bool)
        ]
        if not numbers:
            continue
        print(
            f"  {field:<20} p50={percentile(numbers, 0.50):7.3f}ms"
            f"  p95={percentile(numbers, 0.95):7.3f}ms"
            f"  n={len(numbers)}"
        )


def main():
    records = parse(sys.stdin)
    if not records:
        print("No [MuchoCore Perf] records found. Enable temporary profiling and run level-list POST requests.")
        return 1
    report("ALL", records)
    report("CACHE HIT", [x for x in records if x.get("cache_hit") is True])
    report("CACHE MISS", [x for x in records if x.get("cache_hit") is False])
    print("Nested SQL/cache spans overlap handler time; do not add them to top-level phases.")
    print("PHP total excludes Caddy/TCP overhead, and this is not a load-capacity benchmark.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
