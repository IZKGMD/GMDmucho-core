# MuchoCore VPS: repeatable A/B/A performance experiment

This experiment compares an **existing** configuration against the normal
PHP application, without changing MariaDB data or disabling MuchoProtect.
It measures the latency goal rather than assuming a twofold speedup.

The PHP profiler measured about 2.18 ms median connecting to the DB.
Persistent PDO was intentionally not selected, because the official PHP
manual warns that PDO persistent connections do not clean up database session
state between requests.

Two opt-ins already exist: MUCHO_FAST_LEVEL_BOOT=1 and
MUCHO_FILE_CACHE_EXPERIMENT=1. The first avoids instantiating irrelevant
controllers for prechecked level-list requests; the second changes its cache
from MariaDB to private local files. During this app-only test, worker-originated
cache invalidation still uses the normal database backend, so file entries
can briefly stay stale until their short TTL expires. Test only when a brief
app restart and short-lived cache staleness are acceptable.

## Run on an authorized VPS

The experiment recreates **app only**, twice. MariaDB, worker and Caddy are
not restarted by the script. Choose a quiet maintenance window, preferably
without ongoing level uploads or moderation changes.

After updating main, verify the app has its normal settings:

~~~bash
cd /opt/mucho-core
docker compose exec -T app sh -c \
  'echo "FAST=$MUCHO_FAST_LEVEL_BOOT FILE=$MUCHO_FILE_CACHE_EXPERIMENT PROFILE=$MUCHO_PERF_PROFILE DRIVER=$MUCHO_CACHE_DRIVER"'
~~~

Expected: FAST=0 FILE=0 PROFILE=0 DRIVER=database.
If it differs, the probe refuses to start instead of guessing how to
restore your custom settings.

Preview without changing Docker:

~~~bash
python3 tools/performance/vps_ab.py --host-header muchogdps.space --dry-run
~~~

Run the same read-only localhost level-list test:

~~~bash
python3 tools/performance/vps_ab.py \
  --host-header muchogdps.space \
  --count 100 --interval-ms 600 \
  --confirm-app-restarts
~~~

The script verifies normal settings, tests 100 requests, switches to Fast Boot
plus File Cache (profiling off), repeats, restores normal settings and repeats
the baseline. It compares experimental p50/p95/p99 to the average of two
baseline observations to reveal incidental host noise.

A try/finally restores normal app flags even if a benchmark fails or a signal
interrupts it. Verify manually after a Docker daemon failure.
Results are printed and saved under a private temporary directory
(/tmp/muchocore-ab-*/summary.json). Only summary metrics and response SHA-256
digests, never request credentials or level bodies, are written.

The test stops if a response is rejected (-1), empty (-2), or a benchmark
encounters transport errors. Response hash mismatches are warnings: live level
data and time-dependent fields can legitimately change across phases.

## Evaluate honestly

A twofold improvement in latency requires cutting the **same metric** in
half under comparable workloads. An improvement in PHP app initialization
does not imply an equal improvement in whole-request p95.

Paced requests are about 1.7/s by design, not maximum RPS.
Repeat experiments during comparable load before changing defaults.
The file cache must also pass GD 2.209 real-client smoke tests and cross-worker
invalidation checks before being enabled permanently.
Never disable MuchoProtect to benchmark a busy 1 GiB production VPS.
