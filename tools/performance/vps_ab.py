#!/usr/bin/env python3
"""Reversible, rate-limit-aware A/B/A probe of MuchoCore level-list hot path.

Compare the already available opt-in switches without permanently changing
the live deployment. Intended only for the operator's own localhost VPS.
This is *not* a native Geometry Dash protocol certification or capacity test.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
BENCH = ROOT / "tools/performance/http_latency.py"
URL = "http://127.0.0.1/database/getGJLevels21.php"
POST = b"type=0&page=0&gameVersion=22&binaryVersion=51"
FLAGS = ("MUCHO_FAST_LEVEL_BOOT", "MUCHO_FILE_CACHE_EXPERIMENT", "MUCHO_PERF_PROFILE")
EXPECTED = {
    "MUCHO_FAST_LEVEL_BOOT": "0",
    "MUCHO_FILE_CACHE_EXPERIMENT": "0",
    "MUCHO_PERF_PROFILE": "0",
    "MUCHO_CACHE_DRIVER": "database",
}


def valid_host(value: str) -> bool:
    """Host header only; never a URL, port, newline or arbitrary shell text."""
    return (
        len(value) <= 253
        and re.fullmatch(r"[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?", value) is not None
        and all(0 < len(label) <= 63 for label in value.split("."))
        and ".." not in value
    )


def compose(*args: str, env: dict[str, str] | None = None, capture: bool = False) -> str:
    result = subprocess.run(
        ["docker", "compose", *args],
        cwd=ROOT, env=env, text=True, check=True,
        stdout=subprocess.PIPE if capture else None,
        stderr=subprocess.PIPE if capture else None,
    )
    return result.stdout.strip() if capture else ""


def read_flag(name: str) -> str:
    return compose("exec", "-T", "app", "printenv", name, capture=True)


def require_initial_state() -> None:
    for name, expected in EXPECTED.items():
        found = read_flag(name)
        if found != expected:
            raise RuntimeError(
                f"Refusing experiment: app {name}={found!r} (expected {expected!r}). "
                "Restore your own baseline flags before running this probe."
            )


def switch(*, experimental: bool) -> None:
    flags = {
        "MUCHO_FAST_LEVEL_BOOT": "1" if experimental else "0",
        "MUCHO_FILE_CACHE_EXPERIMENT": "1" if experimental else "0",
        "MUCHO_PERF_PROFILE": "0",
    }
    env = os.environ.copy()
    env.update(flags)
    compose("up", "-d", "--no-deps", "--force-recreate", "app", env=env)
    for key, value in flags.items():
        actual = read_flag(key)
        if actual != value:
            raise RuntimeError(f"App flag verification failed: {key}={actual!r}, expected {value}")


def request(host: str, url: str, post: bool) -> bytes:
    req = urllib.request.Request(
        url,
        data=POST if post else None,
        headers={
            "Host": host,
            "User-Agent": "MuchoCore-Local-AB-Probe/1.0",
            **({"Content-Type": "application/x-www-form-urlencoded"} if post else {}),
        },
        method="POST" if post else "GET",
    )
    with urllib.request.urlopen(req, timeout=5) as response:
        if not 200 <= response.status < 300:
            raise RuntimeError(f"Unexpected HTTP {response.status}")
        body = response.read(1024 * 1024 + 1)
        if len(body) > 1024 * 1024:
            raise RuntimeError("Response larger than 1 MiB")
        return body


def wait_health(host: str) -> None:
    for _ in range(30):
        try:
            if request(host, "http://127.0.0.1/health", False).strip() == b"1":
                return
        except (OSError, urllib.error.URLError, RuntimeError):
            pass
        time.sleep(0.5)
    raise RuntimeError("Local /health did not recover after app recreation")


def sample_wire(host: str) -> str:
    body = request(host, URL, True)
    if not body or body.strip() in (b"-1", b"-2"):
        raise RuntimeError(
            "Non-representative GD response (-1, -2, or empty); "
            "stop rather than benchmarking rejected/empty searches."
        )
    return hashlib.sha256(body).hexdigest()


def run_benchmark(host: str, count: int, interval: int) -> dict:
    cmd = [
        sys.executable, str(BENCH), "--url", URL,
        "--host-header", host, "--method", "POST",
        "--data", POST.decode("ascii"),
        "--count", str(count), "--warmup", "5",
        "--concurrency", "1", "--interval-ms", str(interval),
    ]
    result = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True)
    try:
        data = json.loads(result.stdout)
    except (ValueError, TypeError) as error:
        raise RuntimeError(f"Benchmark produced invalid JSON: {result.stderr.strip()}") from error
    if result.returncode or data.get("successful") != count or data.get("failures") != 0:
        raise RuntimeError(
            "Benchmark had rejected/failed requests: "
            + json.dumps(
                {"successful": data.get("successful"),
                 "failures": data.get("failures"),
                 "failure_types": data.get("failure_types")},
                ensure_ascii=False,
            )
        )
    return data


def comparison(baseline_before: dict, experiment: dict, baseline_after: dict) -> dict:
    result = {}
    for metric in ("p50", "p95", "p99"):
        first = baseline_before["latency_ms"][metric]
        second = baseline_after["latency_ms"][metric]
        candidate = experiment["latency_ms"][metric]
        avg = (first + second) / 2
        result[metric] = {
            "baseline_before_ms": first,
            "baseline_after_ms": second,
            "experimental_ms": candidate,
            "baseline_average_ms": round(avg, 3),
            "relative_change_percent": round((candidate / avg - 1) * 100, 2),
            "observed_speedup_x": round(avg / candidate, 3),
        }
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--host-header", required=True,
                        help="Local tenant virtual host, e.g. gdps.example.test")
    parser.add_argument("--count", type=int, default=100)
    parser.add_argument("--interval-ms", type=int, default=600)
    parser.add_argument("--confirm-app-restarts", action="store_true",
                        help="Allow two short app-only recreations and restore on interruption")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    if not valid_host(args.host_header):
        parser.error("Use only a plain DNS Host header (no URL, port or credentials)")
    if not 20 <= args.count <= 200 or not 600 <= args.interval_ms <= 3000:
        parser.error("Safe limits: --count 20..200; --interval-ms 600..3000")
    if args.dry_run:
        print("A/B/A: normal -> FAST=1 + FILE_CACHE=1 -> normal; "
              "PROFILER=0 throughout. All requests target 127.0.0.1.")
        return 0
    if not args.confirm_app_restarts:
        parser.error("Recreating app interrupts traffic briefly. Pass --confirm-app-restarts.")

    # Let KeyboardInterrupt / SIGTERM invoke finally and restore app flags.
    def interrupted(_signum, _frame):
        raise KeyboardInterrupt("Interrupted; restoring normal app configuration")

    signal.signal(signal.SIGTERM, interrupted)
    signal.signal(signal.SIGINT, interrupted)

    require_initial_state()
    wait_health(args.host_header)
    output_dir = Path(tempfile.mkdtemp(prefix="muchocore-ab-"))
    results: dict[str, dict] = {}
    must_restore = False

    try:
        for name, experimental in (
            ("baseline_before", False),
            ("fast_file_cache", True),
            ("baseline_after", False),
        ):
            if name == "fast_file_cache":
                must_restore = True  # mark BEFORE changing any container state
                switch(experimental=True)
                wait_health(args.host_header)
            elif name == "baseline_after":
                switch(experimental=False)
                must_restore = False
                wait_health(args.host_header)

            print(f"[MuchoCore A/B] Running {name} "
                  f"({args.count} read-only POSTs, {args.interval_ms}ms spacing)...",
                  flush=True)
            digest = sample_wire(args.host_header)
            data = run_benchmark(args.host_header, args.count, args.interval_ms)
            results[name] = {"wire_sha256": digest, **data}
            p = data["latency_ms"]
            print(f"[MuchoCore A/B] {name}: "
                  f"p50={p['p50']}ms p95={p['p95']}ms p99={p['p99']}ms; "
                  f"{data['successful']}/{args.count} accepted", flush=True)
    finally:
        if must_restore:
            print("[MuchoCore A/B] Restoring original flags (FAST=0 FILE=0 PROFILE=0)...",
                  flush=True)
            switch(experimental=False)
            wait_health(args.host_header)

    metrics = comparison(
        results["baseline_before"],
        results["fast_file_cache"],
        results["baseline_after"],
    )
    equal_wire = len({x["wire_sha256"] for x in results.values()}) == 1
    report = {
        "measurement": "fresh TCP HTTP connections; sequential A/B/A, no capacity claims",
        "variants": results,
        "comparison": metrics,
        "wire_samples_identical": equal_wire,
        "flags_restored": all(read_flag(k) == v for k, v in EXPECTED.items()),
    }
    out = output_dir / "summary.json"
    out.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    os.chmod(out, 0o600)
    print("[MuchoCore A/B] Compared to the mean of two baseline runs:")
    for key, metric in metrics.items():
        print(f"  {key}: {metric['observed_speedup_x']}x "
              f"({metric['relative_change_percent']:+.2f}% latency)")
    if not equal_wire:
        print("[MuchoCore A/B] WARNING: response bodies differ; "
              "check game data/timestamp changes and native client compatibility.")
    print("[MuchoCore A/B] Flags restored, report:", out)
    print("[MuchoCore A/B] These are single samples, not proof of stable gains or GD 2.209 wire acceptance.")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (RuntimeError, subprocess.CalledProcessError, KeyboardInterrupt) as error:
        print(f"[MuchoCore A/B] ERROR: {error}", file=sys.stderr)
        sys.exit(1)
