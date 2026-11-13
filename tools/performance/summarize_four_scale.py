#!/usr/bin/env python3
"""Summarize completed isolated 1k/2k/5k/10k GDPS account+level tests.

Never contacts a host and never exposes credentials or synthetic row contents.
"""
import argparse
import json
import pathlib
import re

SIZES = (1000, 2000, 5000, 10000)
SCENARIOS = ("gd_latest", "gd_id", "gd_text", "gd_download", "gd_profile")
MEMORY = re.compile(r"^([0-9.]+)(B|KiB|MiB|GiB) /")
UNITS = {"B": 1 / 1024**2, "KiB": 1 / 1024, "MiB": 1, "GiB": 1024}


def peak_mem_mib(path: pathlib.Path, container: str) -> float | None:
    values = []
    for line in path.read_text(encoding="utf-8").splitlines():
        parts = line.split("|")
        if len(parts) != 3 or parts[0] != container:
            continue
        value = MEMORY.match(parts[1])
        if value:
            values.append(float(value.group(1)) * UNITS[value.group(2)])
    return round(max(values), 1) if values else None


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--report-dir", type=pathlib.Path, required=True)
    parser.add_argument("--output", type=pathlib.Path, required=True)
    args = parser.parse_args()

    lines = [
        "# MuchoCore — synthetic PHP 8.3 + MariaDB 11.4 scale test",
        "",
        "Each stage contains the specified number of **accounts, profiles and levels**.",
        "GD 2.209-shaped anonymous HTTP reads were paced at **one request per 450 ms**",
        "to the **loopback-only** Caddy stack. MuchoProtect stayed active.",
        "This is **not** 10,000 concurrent players or a production VPS capacity test.",
        "",
        "| Accounts | Levels | Requests | Failures | Latest p95 | ID p95 | Text p95 | Download p95 | Profile p95 | App peak RAM | DB peak RAM |",
        "|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|",
    ]
    total_requests = 0
    for size in SIZES:
        folder = args.report_dir / str(size)
        fixture = json.loads((folder / "fixture.json").read_text(encoding="utf-8"))
        report = json.loads((folder / "report.json").read_text(encoding="utf-8"))
        if any(fixture.get(name) != size for name in ("accounts", "profiles", "levels", "level_search_index")):
            raise RuntimeError(f"Size {size}: fixture count mismatch: {fixture}")
        if report.get("fixture_levels") != size or report.get("fixture_accounts") != size:
            raise RuntimeError(f"Size {size}: HTTP workload used wrong fixture")
        attempts = int(report["requests"])
        if attempts < 80:
            raise RuntimeError(f"Size {size}: too few measurement requests: {attempts}")
        total_requests += attempts
        routes = report["routes"]
        failures = 0
        for scenario in SCENARIOS:
            row = routes[scenario]
            failures += sum(row["errors"].values())
            if row["p95_ms"] is None or row["success"] < 10:
                raise RuntimeError(f"Size {size}: missing meaningful samples for {scenario}")
        if failures:
            raise RuntimeError(f"Size {size}: detected {failures} HTTP/protocol failures")
        after = (folder / "after.txt").read_text(encoding="utf-8")
        if after.count("oom=false|restarts=0") != 3:
            raise RuntimeError(f"Size {size}: container OOM/restart check missing")

        app_peak = peak_mem_mib(folder / "resources.log", "muchocore-stress-app-1")
        db_peak = peak_mem_mib(folder / "resources.log", "muchocore-stress-db-1")
        p95 = [str(routes[name]["p95_ms"]) for name in SCENARIOS]
        lines.append(
            f"| {size:,} | {size:,} | {attempts} | {failures} | "
            + " | ".join(p95) + " | "
            + (f"{app_peak} MiB" if app_peak is not None else "n/a")
            + " | " + (f"{db_peak} MiB" if db_peak is not None else "n/a") + " |"
        )
    lines.extend([
        "",
        f"**Total measured HTTP requests: {total_requests}**. "
        "All four stages have valid level, search, download and public profile responses.",
        "",
        "The p95 values include loopback HTTP, reverse proxy, PHP and database latency. "
        "Each route has a limited number of samples; compare measurements cautiously.",
        "The fixture uses synthetic, non-login-capable users and tiny level payloads. "
        "No account logins, uploads, real concurrent-player simulation, "
        "write-throughput or native game-client tests are certified.",
        "",
    ])
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text("\n".join(lines), encoding="utf-8")


if __name__ == "__main__":
    main()
