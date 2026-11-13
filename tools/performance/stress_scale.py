#!/usr/bin/env python3
"""Sustained, single-client, loopback-only GD 2.209 read benchmark.

The five scenarios are rotated at a global 450ms request-start interval.
Level searches collectively receive roughly 80 starts/minute, safely under
MuchoProtect per-IP limits. This is NOT a maximum-RPS or simulated-player test.
"""
import argparse
import json
import math
import pathlib
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://127.0.0.1:18080"
PATH_SEARCH = "/database/getGJLevels21.php"
PATH_DOWNLOAD = "/database/downloadGJLevel22.php"
PATH_PROFILE = "/database/getGJUserInfo20.php"
MAX_BODY = 1024 * 1024
INTERVAL_SECONDS = 0.45

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_):
        return None

OPENER = urllib.request.build_opener(NoRedirect)


def quantile(samples, fraction):
    if not samples:
        return None
    vals = sorted(samples)
    return round(vals[max(0, math.ceil(len(vals) * fraction) - 1)], 2)


def scenarios(levels, seq, accounts=1):
    base = {"gameVersion": "22", "binaryVersion": "51"}
    fixture_id = levels - (seq % 20)
    expected = f"Synthetic Stress Level {fixture_id:05d}".encode("ascii")
    account_id = accounts - (seq % 20) if accounts > 1 else 1
    username = (f"StressUser{account_id:05d}" if accounts > 1 else "StressRobot").encode("ascii")
    return [
        ("gd_latest", PATH_SEARCH, dict(base, type="0", page="0"), b"Synthetic Stress Level"),
        ("gd_id", PATH_SEARCH, dict(base, type="0", str=str(fixture_id), page="0"), expected),
        ("gd_text", PATH_SEARCH, dict(base, type="0", str="Synthetic Stress Level", page="0"), b"Synthetic Stress Level"),
        ("gd_download", PATH_DOWNLOAD,
         dict(base, levelID=str(fixture_id), extras="1", inc="0"), expected),
        ("gd_profile", PATH_PROFILE, dict(base, targetAccountID=str(account_id)), username),
    ]


def probe(path, params, required):
    payload = urllib.parse.urlencode(params).encode("ascii")
    req = urllib.request.Request(
        BASE + path, data=payload, method="POST",
        headers={"User-Agent": "MuchoCore-Synthetic-Scale/1.0",
                 "Content-Type": "application/x-www-form-urlencoded"}
    )
    began = time.perf_counter()
    try:
        with OPENER.open(req, timeout=6) as resp:
            body = resp.read(MAX_BODY + 1)
            code = resp.status
        ms = (time.perf_counter() - began) * 1000
        if code != 200:
            return None, f"http_{code}", 0
        if not body or len(body) > MAX_BODY:
            return None, "empty_or_oversize", len(body)
        if body.strip() in (b"-1", b"-2"):
            return None, "gd_rejected", len(body)
        if required not in body:
            return None, "unexpected_wire_payload", len(body)
        return round(ms, 3), None, len(body)
    except urllib.error.HTTPError as error:
        return None, f"http_{error.code}", 0
    except (urllib.error.URLError, TimeoutError, OSError) as error:
        return None, "network_" + type(error).__name__, 0


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--levels", type=int, choices=(1000, 2000, 5000, 10000), required=True)
    parser.add_argument("--accounts", type=int, choices=(1, 1000, 2000, 5000, 10000), default=1)
    parser.add_argument("--duration", type=int, default=90,
                        help="Duration in seconds (20..180), default 90")
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    if not 20 <= args.duration <= 180:
        parser.error("Duration must be 20..180 seconds")
    if args.accounts not in (1, args.levels):
        parser.error("--accounts must be 1 (legacy) or match --levels")
    results = {name: {"samples": [], "errors": {}, "bytes": 0}
               for name, *_ in scenarios(args.levels, 0, args.accounts)}

    print(f"Scale benchmark: {args.levels} levels, {args.accounts} accounts; {args.duration}s; 5 scenarios (3 searches);",
          "no spoofed IPs, no uploads, no counter increments", flush=True)
    # Validate every route before collecting performance statistics.
    for name, path, params, required in scenarios(args.levels, 0, args.accounts):
        _, error, _ = probe(path, params, required)
        if error:
            print(f"PREFLIGHT_FAILED {name}: {error}", flush=True)
            return 1
    print("PREFLIGHT_OK: list, ID lookup, text search, download, profile", flush=True)

    began = time.perf_counter()
    next_start = began
    next_progress = 15
    attempts = 0
    while True:
        now = time.perf_counter()
        if now - began >= args.duration:
            break
        if next_start > now:
            time.sleep(next_start - now)
        current = scenarios(args.levels, attempts // 5, args.accounts)[attempts % 5]
        name, path, params, required = current
        latency, error, size = probe(path, params, required)
        row = results[name]
        row["bytes"] += size
        if error:
            row["errors"][error] = row["errors"].get(error, 0) + 1
        else:
            row["samples"].append(latency)
        attempts += 1
        next_start = max(time.perf_counter(), next_start + INTERVAL_SECONDS)
        elapsed = time.perf_counter() - began
        if elapsed >= next_progress:
            print(f"PROGRESS {min(args.duration, round(elapsed))}/{args.duration}s "
                  f"requests={attempts} errors={sum(sum(x['errors'].values()) for x in results.values())}",
                  flush=True)
            next_progress += 15

    elapsed = time.perf_counter() - began
    summary = {}
    for name, row in results.items():
        timings = row["samples"]
        errors = row["errors"]
        summary[name] = {
            "success": len(timings),
            "errors": errors,
            "p50_ms": quantile(timings, .5),
            "p95_ms": quantile(timings, .95),
            "p99_ms": quantile(timings, .99),
            "max_ms": round(max(timings), 2) if timings else None,
            "response_bytes_total": row["bytes"],
        }
        print(f"{name}: success={len(timings)} errors={errors} "
              f"p95={summary[name]['p95_ms']}ms", flush=True)

    report = {
        "target": BASE,
        "fixture_levels": args.levels,
        "fixture_accounts": args.accounts,
        "duration_s": round(elapsed, 2),
        "requests": attempts,
        "pacing_limited_requests_per_s": round(attempts / elapsed, 2),
        "client_concurrency": 1,
        "protection": "unchanged; each route below per-IP defaults",
        "mutations_during_measurement": False,
        "description": "Mixed GD 2.209 read workload; NOT maximum RPS/players.",
        "routes": summary,
    }
    output = pathlib.Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print("REPORT=" + str(output.resolve()), flush=True)
    return 1 if any(r["errors"] for r in summary.values()) else 0


if __name__ == "__main__":
    raise SystemExit(main())
