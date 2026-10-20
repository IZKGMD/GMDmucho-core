# MuchoCore: optional reduced GD level-search bootstrap

Based on a local VPS measurement of 105 PHP requests, level-list calls
spent a median of ~4.82 ms in `app_init` and only ~1.65 ms in the handler.
101/105 were served by cache, so heavy SQL tuning is **not** the best first
target for that workload.

This experiment avoids creating unrelated controllers/services/route handlers
when an **already checked** front-controller request targets canonical
`getGJLevels21`. Its public level-list service, encoder, cache, moderation
and authentication checks are the same as in the full path. Plugin lifecycle
hooks `server.boot`, `request.received` and `request.completed` still run;
unrelated endpoints retain the full application bootstrap.

It defaults **OFF** (`MUCHO_FAST_LEVEL_BOOT=0`). This avoids unexpected
changes on GDPS with custom plugins. Do not promote to default until a
representative A/B check and native-client smoke test both pass.

## Single-VPS experiment

Run the normal main updater to install this change, then collect two
local read-only searches on the same live dataset.

**Baseline** (both debug and fast path off):

```bash
docker compose up -d --no-deps --force-recreate app
python3 tools/performance/http_latency.py \
  --url http://127.0.0.1/database/getGJLevels21.php \
  --host-header muchogdps.space --method POST \
  --data 'type=0&page=0&gameVersion=22&binaryVersion=51' \
  --count 100 --warmup 5 --concurrency 1 --interval-ms 600
```

**Experimental variant** (fast path on, profiler off):

```bash
MUCHO_FAST_LEVEL_BOOT=1 docker compose up -d --no-deps --force-recreate app
docker compose exec -T app printenv MUCHO_FAST_LEVEL_BOOT
python3 tools/performance/http_latency.py \
  --url http://127.0.0.1/database/getGJLevels21.php \
  --host-header muchogdps.space --method POST \
  --data 'type=0&page=0&gameVersion=22&binaryVersion=51' \
  --count 100 --warmup 5 --concurrency 1 --interval-ms 600
```

To inspect application stage times too, briefly use both
`MUCHO_FAST_LEVEL_BOOT=1 MUCHO_PERF_PROFILE=1` in the same
`docker compose up -d --no-deps --force-recreate app` command, then summarize
`docker compose logs --since 5m app` with
`python3 tools/performance/profile_summary.py`.

**Restore defaults** after the experiment:

```bash
docker compose up -d --no-deps --force-recreate app
docker compose exec -T app printenv MUCHO_FAST_LEVEL_BOOT
```

Expected `0`. All containers other than app, database volumes, secrets
and unrelated routes are untouched. Never remove `-v` volumes.

## Interpretation

- The baseline from October 20, 2026 was 100/100 successful
  fresh-connection HTTP requests, p50 ~23.77 ms and p95 ~34.51 ms.
- The profiler-enabled run was 100/100 successful at p50 ~23.05 ms,
  p95 ~40.37 ms. Different consecutive samples and profiler overhead
  prohibit treating the latter as a clean speedup/slowdown comparison.
- Do not compare PHP p95 with HTTP p95 by subtraction: percentiles
  and samples do not align.
- The benchmark is paced at 600ms and does not measure maximum RPS.
- A/B only on an authorized tenant; do not increase concurrency on a busy
  1 GiB VPS. Native GD 2.209 needs separate real-client validation.

Rollback is immediate: recreate app without the temporary flag.
