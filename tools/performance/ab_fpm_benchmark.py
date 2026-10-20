#!/usr/bin/env python3
"""Compare two authorized loopback-only PHP-FPM stacks with identical data.

No authentication or mutation requests. Cold-connection HTTP timings include
nginx + PHP-FPM + PDO + MariaDB, not public Internet latency. This is *not*
a maximum-RPS test, since search requests are paced to respect MuchoProtect.
"""
import argparse
import hashlib
import json
import math
import statistics
import time
import urllib.error
import urllib.parse
import urllib.request


def quantile(samples, fraction):
    return round(sorted(samples)[max(0, math.ceil(len(samples) * fraction) - 1)], 3)


def request(url, params, timeout):
    data = urllib.parse.urlencode(params).encode("ascii") if params else None
    req = urllib.request.Request(
        url, data=data, method="POST" if data else "GET",
        headers={"User-Agent": "MuchoCore-Isolated-AB/1.0",
                 "Content-Type": "application/x-www-form-urlencoded"} if data
                else {"User-Agent": "MuchoCore-Isolated-AB/1.0"}
    )
    started = time.perf_counter()
    with urllib.request.urlopen(req, timeout=timeout) as response:
        body = response.read(1024 * 1024 + 1)
        status = response.status
    latency_ms = (time.perf_counter() - started) * 1000
    if not (200 <= status < 300) or not body or len(body) > 1024 * 1024:
        raise RuntimeError("Invalid HTTP status, empty body or oversized response")
    if body.strip() in (b"-1", b"-2"):
        raise RuntimeError("GD rejected/empty list; invalid benchmark workload")
    return body, latency_ms


def run_target(base_url, scenario, samples, pause, warmups):
    url = base_url + scenario["path"]
    params = scenario.get("form")
    for _ in range(warmups):
        request(url, params, 5.0)
    measured = []
    digest = None
    for idx in range(samples):
        if idx:
            time.sleep(pause)
        body, ms = request(url, params, 5.0)
        new_digest = hashlib.sha256(body).hexdigest()
        if digest is None:
            digest = new_digest
        elif digest != new_digest:
            raise RuntimeError("Non-deterministic wire response during benchmark")
        measured.append(ms)
    return {
        "n": len(measured),
        "p50_ms": quantile(measured, .5),
        "p95_ms": quantile(measured, .95),
        "p99_ms": quantile(measured, .99),
        "mean_ms": round(statistics.mean(measured), 3),
        "min_ms": round(min(measured), 3),
        "max_ms": round(max(measured), 3),
        "wire_sha256": digest,
        "errors": 0,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--baseline", default="http://127.0.0.1:18081")
    parser.add_argument("--candidate", default="http://127.0.0.1:18082")
    parser.add_argument("--samples", type=int, default=18)
    parser.add_argument("--rounds", type=int, default=2)
    parser.add_argument("--warmups", type=int, default=2)
    parser.add_argument("--pause", type=float, default=0.6)
    parser.add_argument("--output", default="ab-fpm-results.json")
    args = parser.parse_args()

    for base in (args.baseline, args.candidate):
        parsed = urllib.parse.urlsplit(base)
        if parsed.scheme != "http" or parsed.hostname not in ("127.0.0.1", "localhost"):
            parser.error("Only local HTTP loopback endpoints are supported")
        if parsed.username or parsed.password or parsed.path not in ("", "/"):
            parser.error("Credentials and URL paths are not allowed")
    if not (5 <= args.samples <= 25 and 1 <= args.rounds <= 3
            and 0 <= args.warmups <= 3 and 0.5 <= args.pause <= 5):
        parser.error("Out of bounded benchmark limits")

    scenarios = [
        {"name": "search_latest_2209", "path": "/database/getGJLevels21.php",
         "form": {"type": "0", "page": "0", "gameVersion": "22",
                  "binaryVersion": "51"}},
        {"name": "search_by_id_2209", "path": "/database/getGJLevels21.php",
         "form": {"type": "0", "str": "1", "page": "0", "gameVersion": "22",
                  "binaryVersion": "51"}},
    ]
    report = {
        "kind": "MuchoCore-local-PHP-FPM-AB-v1",
        "method": "loopback HTTP, Nginx -> PHP-FPM -> MariaDB, fresh connections",
        "notice": "Synthetic equal fixture; paced read-only requests, not max RPS or VPS measurements.",
        "scenarios": {},
    }

    for scenario in scenarios:
        results = {"baseline": [], "candidate": []}
        for round_num in range(args.rounds):
            order = (("baseline", "candidate") if round_num % 2 == 0
                     else ("candidate", "baseline"))
            for name in order:
                target = getattr(args, name)
                sample = run_target(target, scenario, args.samples, args.pause,
                                    args.warmups)
                results[name].append(sample)
                print(f"{scenario['name']} round {round_num + 1} {name}: "
                      f"p50={sample['p50_ms']}ms p95={sample['p95_ms']}ms",
                      flush=True)

        baseline_hashes = {r["wire_sha256"] for r in results["baseline"]}
        candidate_hashes = {r["wire_sha256"] for r in results["candidate"]}
        if len(baseline_hashes | candidate_hashes) != 1:
            raise RuntimeError(scenario["name"] + ": GD wire responses differ between versions")
        report["scenarios"][scenario["name"]] = {
            "rounds": results,
            "mean_p95_ms": {
                k: round(statistics.mean(row["p95_ms"] for row in v), 3)
                for k, v in results.items()
            },
            "wire_identical": True,
        }
    with open(args.output, "w", encoding="utf-8") as out:
        json.dump(report, out, indent=2)
    print(json.dumps(report, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
