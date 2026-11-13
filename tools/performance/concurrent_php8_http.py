#!/usr/bin/env python3
"""Bounded PHP 8 HTTP concurrency test for the isolated MuchoCore stress stack.

Measures real Caddy -> PHP-FPM -> MariaDB requests on localhost only.
No invented IPs, protection bypass, production data, or automatic deployment.
Writes require a private synthetic-account fixture and a second explicit opt-in.
"""
import argparse
from concurrent.futures import ThreadPoolExecutor
import json
import math
from pathlib import Path
import re
import stat
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://127.0.0.1:18080"
MAX_BODY = 1024 * 1024
ALLOWED_WRITES = {
    "/database/uploadGJComment21.php",
    "/database/uploadGJAccComment20.php",
}


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_args):
        return None


OPENER = urllib.request.build_opener(NoRedirect)

READ_JOBS = [
    {
        "name": "search_latest",
        "path": "/database/getGJLevels21.php",
        "form": {"type": "0", "page": "0", "gameVersion": "22", "binaryVersion": "51"},
        "expected": "level",
    },
    {
        "name": "search_text",
        "path": "/database/getGJLevels21.php",
        "form": {"type": "0", "str": "Synthetic Stress Level", "page": "0",
                 "gameVersion": "22", "binaryVersion": "51"},
        "expected": "level",
    },
    {
        "name": "download_readonly",
        "path": "/database/downloadGJLevel22.php",
        "form": {"levelID": "1", "inc": "0", "gameVersion": "22",
                 "binaryVersion": "51"},
        "expected": "level",
    },
    {
        "name": "profile",
        "path": "/database/getGJUserInfo20.php",
        "form": {"targetAccountID": "1", "gameVersion": "22", "binaryVersion": "51"},
        "expected": "profile",
    },
]


def percentile(values, fraction):
    if not values:
        return None
    ordered = sorted(values)
    return round(ordered[max(0, math.ceil(len(ordered) * fraction) - 1)], 2)


def isolated_stack_guard():
    if Path(".env").exists():
        raise RuntimeError("Refused: .env exists; use a clean isolated stress checkout")
    if not Path("docker-compose.stress.yml").is_file():
        raise RuntimeError("Run from the MuchoCore repository root")
    command = ["docker", "compose", "-f", "docker-compose.stress.yml"]
    env = subprocess.run(command + ["exec", "-T", "app", "printenv", "DB_NAME"],
                         capture_output=True, text=True, timeout=15, check=True)
    if env.stdout.strip() != "mucho_stress":
        raise RuntimeError("Refused: stress app DB_NAME is not mucho_stress")
    port = subprocess.run(command + ["port", "caddy", "80"],
                          capture_output=True, text=True, timeout=15, check=True)
    if port.stdout.strip() != "127.0.0.1:18080":
        raise RuntimeError("Refused: caddy is not restricted to 127.0.0.1:18080")


def load_write_fixture(path):
    source = Path(path).resolve()
    secrets_dir = (Path.cwd() / ".stress-secrets").resolve()
    if secrets_dir not in source.parents:
        raise ValueError("Write fixture must reside under .stress-secrets/")
    if stat.S_IMODE(source.stat().st_mode) & 0o077:
        raise ValueError("Set write fixture permissions to 0600 before running")
    data = json.loads(source.read_text(encoding="utf-8"))
    if data.get("kind") != "muchocore-synthetic-writes-v1":
        raise ValueError("Wrong synthetic write fixture kind")
    jobs = data.get("jobs")
    if not isinstance(jobs, list) or not 1 <= len(jobs) <= 64:
        raise ValueError("Provide 1..64 synthetic write jobs")
    for job in jobs:
        if not isinstance(job, dict) or job.get("path") not in ALLOWED_WRITES:
            raise ValueError("Only synthetic comment-write endpoints are permitted")
        form = job.get("form")
        if not isinstance(form, dict) or not all(
            isinstance(k, str) and isinstance(v, str) for k, v in form.items()
        ):
            raise ValueError("Write form fields must be string pairs")
        if not (form.get("accountID", "").isdigit()
                and int(form["accountID"]) > 0 and form.get("comment")
                and (form.get("gjp2") or form.get("gjp"))):
            raise ValueError("Each write needs synthetic accountID, comment, GJP2/GJP")
        if job.get("expected") not in ("positive_id", "one"):
            raise ValueError("Write expected must be positive_id or one")
    return jobs


def check_body(body, expected):
    if body.strip() in (b"-1", b"-2", b"") or len(body) > MAX_BODY:
        return False
    if expected == "level":
        return b"Synthetic Stress Level" in body
    if expected == "profile":
        return b"StressRobot" in body
    if expected == "positive_id":
        return bool(re.fullmatch(rb"[1-9][0-9]*", body.strip()))
    if expected == "one":
        return body.strip() == b"1"
    return False


def perform(job):
    payload = urllib.parse.urlencode(job["form"]).encode("utf-8")
    req = urllib.request.Request(
        BASE + job["path"], data=payload, method="POST",
        headers={"User-Agent": "MuchoCore-PHP8-Concurrency/1.0",
                 "Content-Type": "application/x-www-form-urlencoded"},
    )
    started = time.perf_counter()
    try:
        with OPENER.open(req, timeout=8) as response:
            data = response.read(MAX_BODY + 1)
            status = response.status
        elapsed = (time.perf_counter() - started) * 1000
        if status != 200:
            return (None, "http_" + str(status))
        if not check_body(data, job["expected"]):
            return (None, "invalid_gd_response")
        return (round(elapsed, 3), None)
    except urllib.error.HTTPError as error:
        return (None, "http_" + str(error.code))
    except (urllib.error.URLError, TimeoutError, OSError) as error:
        return (None, "network_" + type(error).__name__)


def run_stage(kind, workers, jobs, attempts, pause_ms, p95_limit):
    timings, errors = [], {}
    started = time.perf_counter()
    with ThreadPoolExecutor(max_workers=workers) as pool:
        for index in range(0, attempts, workers):
            batch = [jobs[(index + offset) % len(jobs)]
                     for offset in range(min(workers, attempts - index))]
            for latency, error in pool.map(perform, batch):
                if error:
                    errors[error] = errors.get(error, 0) + 1
                else:
                    timings.append(latency)
            if index + workers < attempts and pause_ms:
                time.sleep(pause_ms / 1000.0)
    elapsed = time.perf_counter() - started
    p95 = percentile(timings, 0.95)
    passed = not errors and p95 is not None and p95 <= p95_limit
    return {
        "workload": kind,
        "concurrency": workers,
        "attempts": attempts,
        "success": len(timings),
        "errors": errors,
        "p50_ms": percentile(timings, 0.50),
        "p95_ms": p95,
        "p99_ms": percentile(timings, 0.99),
        "elapsed_s": round(elapsed, 3),
        "accepted_rps_with_client_pacing": round(len(timings) / elapsed, 2),
        "passed": passed,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mode", choices=("read", "write", "mixed", "all"),
                        default="read")
    parser.add_argument("--max-concurrency", type=int, choices=(1, 2, 4, 8),
                        default=8)
    parser.add_argument("--attempts", type=int, default=16)
    parser.add_argument("--pause-ms", type=int, default=1000)
    parser.add_argument("--p95-limit-ms", type=int, default=500)
    parser.add_argument("--write-fixture")
    parser.add_argument("--allow-synthetic-writes", action="store_true")
    parser.add_argument("--output", default="storage/stress-reports/php8-concurrency.json")
    args = parser.parse_args()
    if not 8 <= args.attempts <= 64:
        parser.error("--attempts must be 8..64")
    if not 0 <= args.pause_ms <= 5000:
        parser.error("--pause-ms must be 0..5000")
    if not 50 <= args.p95_limit_ms <= 10000:
        parser.error("--p95-limit-ms must be 50..10000")

    write_jobs = []
    wants_writes = args.mode in ("write", "mixed", "all")
    if wants_writes:
        if not args.allow_synthetic_writes or not args.write_fixture:
            parser.error("Writes require --write-fixture AND --allow-synthetic-writes")
        try:
            write_jobs = load_write_fixture(args.write_fixture)
        except (ValueError, OSError, json.JSONDecodeError) as error:
            parser.error(str(error))
    elif args.write_fixture or args.allow_synthetic_writes:
        parser.error("Write flags are only valid in write/mixed/all modes")

    try:
        isolated_stack_guard()
    except (OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired,
            RuntimeError) as error:
        parser.error("Isolated stack guard failed: " + type(error).__name__
                     + " " + str(error)[:120])

    for job in READ_JOBS:
        if perform(job)[1] is not None:
            parser.error("Synthetic preflight failed: " + job["name"])

    workloads = {
        "read": READ_JOBS,
        "write": write_jobs,
        "mixed": [READ_JOBS[0], READ_JOBS[1], READ_JOBS[2],
                  write_jobs[0]] if write_jobs else [],
    }
    selected = (["read", "write", "mixed"] if args.mode == "all"
                else [args.mode])
    report = {
        "kind": "muchocore-php8-concurrency-v1",
        "target": BASE,
        "database": "mucho_stress",
        "scope": "isolated loopback synthetic; real HTTP PHP-FPM requests",
        "read_only": not wants_writes,
        "rate_limits_unchanged": True,
        "not_a_simultaneous_player_certificate": True,
        "notes": "Single source IP; write job credentials/contents are not reported.",
        "stages": [],
    }
    all_passed = True
    for kind in selected:
        for workers in (1, 2, 4, 8):
            if workers > args.max_concurrency:
                break
            stage = run_stage(kind, workers, workloads[kind], args.attempts,
                              args.pause_ms, args.p95_limit_ms)
            report["stages"].append(stage)
            print(json.dumps(stage, ensure_ascii=False), flush=True)
            if not stage["passed"]:
                all_passed = False
                break
        if not all_passed:
            break

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n",
                      encoding="utf-8")
    print("REPORT=" + str(output.resolve()))
    return 0 if all_passed else 1


if __name__ == "__main__":
    raise SystemExit(main())
