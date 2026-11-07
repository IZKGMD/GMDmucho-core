#!/usr/bin/env python3
"""Bounded loopback-only MuchoCore benchmark; never targets public hosts.

Checks HTTP/GD payload validity and reports p50/p95/p99 plus throughput.
Search traffic is explicitly paced below the default per-IP limits, whereas
health can test small concurrency ramps. This is not a max-RPS certificate.
"""
import argparse
import concurrent.futures
import json
import math
import pathlib
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = 'http://127.0.0.1:18080'
MAX_BODY = 1024 * 1024


def quantile(values, fraction):
    return round(sorted(values)[max(0, math.ceil(len(values) * fraction) - 1)], 2) if values else None


def request(path, form=None):
    payload = urllib.parse.urlencode(form).encode() if form else None
    request_obj = urllib.request.Request(BASE + path, data=payload,
        method='POST' if payload else 'GET', headers={
            'User-Agent': 'MuchoCore-Local-Bounded-Benchmark/1.0',
            **({'Content-Type': 'application/x-www-form-urlencoded'} if payload else {})
        })
    start = time.perf_counter()
    try:
        with urllib.request.urlopen(request_obj, timeout=6) as response:
            body = response.read(MAX_BODY + 1)
            status = response.status
        ms = (time.perf_counter() - start) * 1000
        if status != 200:
            return None, f'HTTP_{status}'
        if len(body) == 0 or len(body) > MAX_BODY:
            return None, 'empty_or_too_big'
        if body.strip() in (b'-1', b'-2'):
            return None, 'GD_rejected_or_empty'
        if path == '/health' and body.strip() != b'1':
            return None, 'health_wrong_body'
        if path.endswith('getGJLevels21.php') and b'Synthetic Stress Level' not in body:
            return None, 'missing_fixture'
        return ms, None
    except urllib.error.HTTPError as exc:
        return None, f'HTTP_{exc.code}'
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        return None, type(exc).__name__


def stage(name, path, form, count, workers, pause):
    print(f'Running {name}: count={count}, workers={workers}, global_pause={pause}s', flush=True)
    durations, errors = [], {}
    start = time.perf_counter()
    if workers == 1:
        for i in range(count):
            begun = time.perf_counter()
            latency, error = request(path, form)
            if error:
                errors[error] = errors.get(error, 0) + 1
            else:
                durations.append(latency)
            if i < count - 1:
                time.sleep(max(0, pause - (time.perf_counter() - begun)))
    else:
        # Concurrency ramp is health-only. Do not blast GD endpoints or
        # spoof client identities to bypass MuchoProtect.
        assert path == '/health' and pause == 0
        with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as pool:
            for latency, error in pool.map(lambda _: request(path, form), range(count)):
                if error:
                    errors[error] = errors.get(error, 0) + 1
                else:
                    durations.append(latency)
    elapsed = time.perf_counter() - start
    row = {
        'stage': name, 'requests': count, 'workers': workers,
        'success': len(durations), 'errors': errors,
        'elapsed_s': round(elapsed, 2),
        'requests_per_s': round(count / elapsed, 2) if elapsed else 0,
        'p50_ms': quantile(durations, .5), 'p95_ms': quantile(durations, .95),
        'p99_ms': quantile(durations, .99),
        'max_ms': round(max(durations), 2) if durations else None,
    }
    print(json.dumps(row, ensure_ascii=False), flush=True)
    return row


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', default='stress-results.json')
    parser.add_argument('--quick', action='store_true')
    args = parser.parse_args()
    base_count = 24 if args.quick else 64
    rows = []
    for concurrency in (1, 2, 4, 8):
        rows.append(stage(f'health_c{concurrency}', '/health', None, base_count, concurrency, 0))
    form = {'type': '0', 'page': '0', 'gameVersion': '22', 'binaryVersion': '51'}
    for name, data in [('gd_latest', form), ('gd_level_id_1', {**form, 'str': '1'})]:
        rows.append(stage(name, '/database/getGJLevels21.php', data,
                          16 if args.quick else 40, 1, .7))
    report = {
        'target': BASE, 'scope': 'local-only-synthetic', 'results': rows,
        'note': 'Paced GD search measures latency at safe request rates, not maximum capacity.',
    }
    output = pathlib.Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print('Report:', output.resolve())
    return 0 if all(not row['errors'] for row in rows) else 1


if __name__ == '__main__':
    raise SystemExit(main())
