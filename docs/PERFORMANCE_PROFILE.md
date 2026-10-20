# MuchoCore — temporary PHP level-search profiler

The profiler answers **where** request time is spent before making more
optimizations. It is **off by default**, works only for the normalized
getGJLevels21 route, and **never changes the HTTP response body** or GD wire.

It emits one JSON line per request to PHP-FPM's error stream with a
`[MuchoCore Perf]` marker. No IP, user identifiers, passwords, request
parameters, SQL strings or response bytes are logged. Disabled paths do not
emit records. Only enable briefly for your own authorized server.

## VPS: enable, measure, disable

From your MuchoCore checkout:

```bash
cd /opt/mucho-core
# Requires this feature's merged revision. Use the standard updater once.
# The value is passed to app only for this container recreation.
MUCHO_PERF_PROFILE=1 docker compose up -d --no-deps --force-recreate app
docker compose exec -T app sh -c 'printf "MUCHO_PERF_PROFILE=%s\n" "$MUCHO_PERF_PROFILE"'
```

Expected: `MUCHO_PERF_PROFILE=1`. Wait for the app health route to return `1`
before measuring. Run your same local **read-only** level-list workload:

```bash
python3 tools/performance/http_latency.py \
  --url http://127.0.0.1/database/getGJLevels21.php \
  --host-header muchogdps.space \
  --method POST \
  --data 'type=0&page=0&gameVersion=22&binaryVersion=51' \
  --count 100 --warmup 5 --concurrency 1 --interval-ms 600
```

Then summarize timings (no raw output of secret fields):

```bash
docker compose logs --since 5m app 2>&1 \
  | python3 tools/performance/profile_summary.py
```

**Turn profiling off immediately after taking measurements:**

```bash
docker compose up -d --no-deps --force-recreate app
docker compose exec -T app sh -c 'printf "MUCHO_PERF_PROFILE=%s\n" "$MUCHO_PERF_PROFILE"'
```

Expected: `MUCHO_PERF_PROFILE=0`. Do **not** set it in permanent
configuration unless a deliberate diagnostic session is intended.
No Docker image rebuild, DB reset, Redis or changes to secrets are needed
for this toggle. Do not delete volumes.

## Interpretation

- `total_ms`: execution from the first instruction in `public/index.php`
  through PHP shutdown. Excludes Caddy/TCP connection and previous upstream
  handling, so do not compare it directly with external request wall time.
- `bootstrap_ms`: early PHP bootstrap, Composer autoload, env/config load,
  routing-normalization. Does not measure FPM queue wait.
- `protection_ms`: MuchoProtect initialization and validation (and potential
  DB connection if protection uses database-backed storage).
- `app_init_ms`: service/controller construction and plugin initialization
  (plus a new DB connection for normal file-backed protection).
- `handler_ms`: version/compatibility, cache, searching, encoding, plugin
  hooks and response preparation.
- `db_connect_ms`: time inside PDO construction (subset of `protection_ms`
  or `app_init_ms`); excludes environment parsing.
- `cache_hit`: `true`/ `false` / `null`. Cache hits avoid the search SQL.
- `cache_read_ms`, `cache_write_ms`, `search_ms`, `sql_levels_ms`,
  `sql_count_ms`, `sql_index_probe_ms`, `encode_ms`: nested timings,
  available only for the operations that ran. **Do not sum nested and
  parent phases.**
- `peak_memory_kib`: PHP's per-request allocated peak (not container RSS).

The analyzer splits requests by cache hit/miss and outputs p50/p95 separately
for each phase. Collect comparable datasets before and after any optimization;
keep query type, interval, PHP worker settings and caching unchanged for
A/B measurements.

**Caution:** The profiler itself has small but nonzero overhead. The existing
100-request HTTP tests confirm correctness and characterize p95 under light
load; they do not establish capacity or certify a native GD 2.209 client.
When multiple tenants or live players are active, use a staging server
for heavier tests instead of increasing the local concurrency.
