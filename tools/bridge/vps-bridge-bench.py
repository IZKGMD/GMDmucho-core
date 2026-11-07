#!/usr/bin/env python3
"""Bounded, read-only benchmark of the isolated localhost stress service.

Never accepts a host, port, path, payload, or concurrency parameter from SSH.
Not a substitute for GD client or load-testing certification.
"""
from __future__ import annotations

import math
import statistics
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

URL = "http://127.0.0.1:18080/health"
REQUESTS = 40
PAUSE_SECONDS = 0.15
TIMEOUT_SECONDS = 3


def main() -> int:
    latencies_ms: list[float] = []
    failures: list[str] = []
    for number in range(1, REQUESTS + 1):
        started = time.perf_counter()
        try:
            request = Request(URL, headers={"User-Agent": "MuchoCore-Bridge/1"})
            with urlopen(request, timeout=TIMEOUT_SECONDS) as response:
                body = response.read(32)
                if response.status != 200 or body.strip() != b"1":
                    failures.append(f"request {number}: unexpected HTTP/body")
                else:
                    latencies_ms.append((time.perf_counter() - started) * 1000)
        except (HTTPError, URLError, TimeoutError, OSError) as exc:
            failures.append(f"request {number}: {type(exc).__name__}")
        if number != REQUESTS:
            time.sleep(PAUSE_SECONDS)

    print(f"Endpoint: localhost:18080/health")
    print(f"Requests: {REQUESTS}; sequential; pause: {PAUSE_SECONDS:.2f}s")
    print(f"Successful: {len(latencies_ms)}; failed: {len(failures)}")
    if latencies_ms:
        sorted_ms = sorted(latencies_ms)
        p95_index = math.ceil(0.95 * len(sorted_ms)) - 1
        print(
            f"Latency (successful only): "
            f"p50={statistics.median(sorted_ms):.1f}ms, "
            f"p95={sorted_ms[p95_index]:.1f}ms, "
            f"max={max(sorted_ms):.1f}ms"
        )
    if failures:
        for failure in failures[:8]:
            print(failure)
        if len(failures) > 8:
            print(f"... {len(failures) - 8} more failures")
    print("Not a gameplay workload, throughput claim, or production benchmark.")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
