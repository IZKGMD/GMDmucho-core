# MuchoCore — experimental file-backed level-list cache

The October 20, 2026 VPS profiler recorded level-cache reads at roughly
**1.1 ms p50** on MariaDB. This is a candidate hot-path cost on requests
already served from cache. An optional file-backed cache can avoid that
SQL round trip, without an extra Redis daemon on a 1 GiB VPS.

**Default remains `MUCHO_CACHE_DRIVER=database`.**
This change **does not prove a speedup**; test it on the target VPS before
making any production decision. It does not touch protocol encoders, passwords,
the database schema or request protection.

## Design / safety

- Only enable by explicitly selecting `MUCHO_CACHE_DRIVER=file` in a
  controlled deployment or the temporary process switch below.
- File entries live under private `storage/cache/files`, outside `public/`.
  Hashed 2-character shard paths avoid directory hotspots; permissions are
  0700 for directories and 0600 for files.
- Base64 payloads round-trip exact GD wire bytes, including binary data.
- Writes use temporary files and atomic rename; reader/writer file locks
  coordinate prefix invalidation across PHP-FPM workers.
- Cached data expires at the same TTL already specified by LevelService
  (15 seconds by default, clamped to 1–300 seconds).
- File I/O errors become cache misses, not failed GD requests.
- Opportunistic bounded sweeps reclaim expired entries. Prefix invalidation
  also deletes expired entries. Unlike MariaDB, this backend stores cached
  responses on the VPS filesystem; verify storage consumption before leaving
  it enabled for longer periods.
- **Short A/B only**: the temporary toggle below recreates app but leaves
  the background worker on database caching. Cross-backend invalidation
  cannot be guaranteed during the brief test. Avoid concurrent live level
  edits, or allow the normal short TTL to expire.

Persistent PDO pooling was deliberately not enabled: sessions, locks,
temporary tables and uncommitted work can leak across requests if a
persistent connection is reused without reliable cleanup.

## Reversible VPS A/B

From a recent `main` checkout with the CI-green file-cache feature,
test the same type=0 GD 2.209 read-only POST in both variants:

```bash
cd /opt/mucho-core
MUCHO_UPDATE_CHANNEL=main bash update.sh

# Baseline: fast route ON; database cache (default).
MUCHO_FAST_LEVEL_BOOT=1 docker compose up -d --no-deps --force-recreate app
docker compose exec -T app sh -c 'echo "FAST=$MUCHO_FAST_LEVEL_BOOT CACHE=$MUCHO_FILE_CACHE_EXPERIMENT"'
python3 tools/performance/http_latency.py \
  --url http://127.0.0.1/database/getGJLevels21.php \
  --host-header muchogdps.space \
  --method POST \
  --data 'type=0&page=0&gameVersion=22&binaryVersion=51' \
  --count 100 --warmup 5 --concurrency 1 --interval-ms 600

# Experimental: same route + file cache instead of MariaDB cache.
MUCHO_FAST_LEVEL_BOOT=1 MUCHO_FILE_CACHE_EXPERIMENT=1 \
  docker compose up -d --no-deps --force-recreate app
docker compose exec -T app sh -c 'echo "FAST=$MUCHO_FAST_LEVEL_BOOT CACHE=$MUCHO_FILE_CACHE_EXPERIMENT"'
python3 tools/performance/http_latency.py \
  --url http://127.0.0.1/database/getGJLevels21.php \
  --host-header muchogdps.space \
  --method POST \
  --data 'type=0&page=0&gameVersion=22&binaryVersion=51' \
  --count 100 --warmup 5 --concurrency 1 --interval-ms 600

# Roll back immediately, without modifying .env or touching database volumes.
docker compose up -d --no-deps --force-recreate app
docker compose exec -T app sh -c 'echo "FAST=$MUCHO_FAST_LEVEL_BOOT CACHE=$MUCHO_FILE_CACHE_EXPERIMENT"'
```

Expected flags: baseline `FAST=1 CACHE=0`, experiment `FAST=1 CACHE=1`,
rollback `FAST=0 CACHE=0`. Do not compare `completed_requests_per_second`
(artificially limited by 600 ms pacing). Compare p50/p95/p99 **and**
100/100 success. Repeat ABBA if differences are close, as VPS jitter is large.

Optional targeted profiling: run the experiment with
`MUCHO_PERF_PROFILE=1` and use `tools/performance/profile_summary.py`
to check `cache_read_ms`; do not mix profiled and non-profiled results.

Avoid turning the file backend on permanently until you have checked
real-player GD 2.209 responses and validated background-worker cache
invalidation on your deployment.
