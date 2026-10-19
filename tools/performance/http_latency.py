#!/usr/bin/env python3
"""Bounded HTTP latency probe for an authorized staging GDPS.

Measures fresh-connection end-to-end latency; not native-client acceptance,
PHP handler time, or a load-capacity certification. No secrets are logged.
"""

import argparse
import json
import math
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor


def quantile(values, percent):
    if not values:
        return None
    ordered = sorted(values)
    return round(ordered[max(0, math.ceil(percent * len(ordered)) - 1)], 3)


def probe(url, method, payload, timeout, host_header):
    headers = {"User-Agent": "MuchoCore-Staging-Latency-Probe/1.0"}
    if host_header:
        headers["Host"] = host_header
    if method == "POST":
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    request = urllib.request.Request(
        url, data=payload if method == "POST" else None,
        headers=headers, method=method
    )
    start = time.perf_counter()
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            data = response.read(1024 * 1024 + 1)
            code = response.status
            if len(data) > 1024 * 1024:
                return None, "response_over_1MiB"
    except urllib.error.HTTPError as error:
        return None, "http_" + str(error.code)
    except (urllib.error.URLError, TimeoutError, OSError) as error:
        return None, "network_" + type(error).__name__

    elapsed = (time.perf_counter() - start) * 1000
    # Geometry Dash uses a success HTTP status with -1 as a wire error.
    if not (200 <= code < 300):
        return None, "http_" + str(code)
    if data.strip() == b"-1":
        return None, "gd_rejected"
    return elapsed, None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", required=True, help="Full staging URL")
    parser.add_argument("--method", choices=("GET", "POST"), default="GET")
    parser.add_argument(
        "--data", default="", help="URL-encoded form data (do not include secrets)"
    )
    parser.add_argument("--host-header", default="", help="Virtual host for local Caddy")
    parser.add_argument("--count", type=int, default=50)
    parser.add_argument("--warmup", type=int, default=5)
    parser.add_argument("--concurrency", type=int, default=1)
    parser.add_argument("--timeout", type=float, default=5)
    parser.add_argument(
        "--allow-remote", action="store_true",
        help="Explicitly allow authorized non-loopback staging (never public load tests)"
    )
    args = parser.parse_args()

    parsed = urllib.parse.urlsplit(args.url)
    if parsed.scheme not in ("http", "https") or not parsed.hostname:
        parser.error("--url must use http:// or https://")
    if parsed.username or parsed.password or parsed.fragment:
        parser.error("Credentials and fragments are forbidden in --url")
    if not args.allow_remote and parsed.hostname not in (
        "localhost", "127.0.0.1", "::1"
    ):
        parser.error("Only localhost is allowed without --allow-remote")
    if args.data and args.method != "POST":
        parser.error("--data requires --method POST")
    if any(x in (args.data.lower() + parsed.query.lower()) for x in (
        "gjp=", "gjp2=", "password=", "secret=", "token=", "udid=", "uuid="
    )):
        parser.error("Never benchmark with credential/device fields")
    if not (1 <= args.count <= 1000 and 0 <= args.warmup <= 100):
        parser.error("Count must be 1..1000 and warmup 0..100")
    if not (1 <= args.concurrency <= 8 and 0.2 <= args.timeout <= 30):
        parser.error("Concurrency must be 1..8 and timeout 0.2..30")
    if args.host_header and (not args.host_header.isascii()
                             or any(c in args.host_header for c in "\r\n/")):
        parser.error("Invalid virtual Host header")

    payload = args.data.encode("ascii") if args.data else b""
    kw = (args.url, args.method, payload, args.timeout, args.host_header)
    for _ in range(args.warmup):
        probe(*kw)

    samples = []
    errors = {}
    start = time.perf_counter()
    with ThreadPoolExecutor(max_workers=args.concurrency) as pool:
        for elapsed, reason in pool.map(lambda _: probe(*kw), range(args.count)):
            if reason is None:
                samples.append(elapsed)
            else:
                errors[reason] = errors.get(reason, 0) + 1
    duration = time.perf_counter() - start

    report = {
        "count": args.count,
        "successful": len(samples),
        "failures": sum(errors.values()),
        "failure_types": errors,
        "concurrency": args.concurrency,
        "method": args.method,
        "elapsed_seconds": round(duration, 3),
        "completed_requests_per_second": (
            round(args.count / duration, 2) if duration else None
        ),
        "latency_ms": {
            "min": round(min(samples), 3) if samples else None,
            "p50": quantile(samples, 0.50),
            "p95": quantile(samples, 0.95),
            "p99": quantile(samples, 0.99),
            "max": round(max(samples), 3) if samples else None,
        },
        "measurement": "fresh HTTP connections (network + proxy + PHP + DB)",
        "certifies_native_gd_client": False,
    }
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
