# MuchoCore performance pass — October 19, 2026

This is a **targeted core optimization**, not a measured global speedup or a
full 1.1.0 release certification. It changes internals only; Geometry Dash
request/response wire formats and the stable-release marker remain unchanged.

## Bottlenecks addressed

| Area | Before | Now | Primary guard |
|---|---|---|---|
| Background queue | Workers wait on the oldest locked job | `FOR UPDATE SKIP LOCKED` reserves other available jobs on MariaDB 10.6+; older engines fall back | Isolated concurrent MariaDB worker test |
| Download deduplication | Every player download scans/deletes *all* expired rows for its level | Delete only expired slots for the requesting `(level_id, client_hash)` through the existing composite unique index | Two-download-per-client, expiry reuse and counter checks |
| Deduplication storage | Popular levels can accumulate expiry rows until a download reaps all of them | The background worker prunes up to 512 expired rows per minute, outside the request path using the existing timestamp index | MariaDB integration plus existing dedupe contract |
| Search indexing | Unbounded IDs; then one extra SELECT per level even after pagination | Fixed 256-row keyset batches fetch required level/creator fields in one joined read, avoiding N+1 SELECTs; high-water ID and no OFFSET | 700-level MariaDB integration + SELECT-count regression |
| Redis invalidation | Prefix invalidation uses synchronous `DEL` for all matches | `UNLINK` asynchronously reclaims payloads when supported, with `DEL` fallback | Literal wildcard + multi-batch Redis test |
| PHP runtime | Distribution-default OPcache limits | Conservative cache sizing and realpath cache; **timestamps remain validated** | Docker build + existing PHP and routing tests |

## What this does NOT do

- No claim of a 10x improvement, of lower p95, or of solved network/game latency.
- No database migrations or indexes added: existing indexes are reused.
- No change to GD protocol framing or authentication.
- No certification of previously unverified GD 2.209 endpoints.
- No production database benchmark has been run.
- The 300-level search-index SELECT-count test proves fewer reads; it does **not** measure latency in milliseconds or p95.


## Additional end-to-end latency work

- **No duplicate protection backend initialization:** `public/index.php`
  already completes the MuchoProtect precheck. `Application` now only
  constructs its own guard for direct/standalone handlers, preserving both
  security paths while avoiding an unnecessary second DB connection when
  DB-backed penalties and rate limits are enabled. In DB-backed mode,
  the front controller also passes that same PDO connection into
  `Application` so protection and gameplay do not establish two independent
  database sessions. Standalone `new Application()` remains supported.
- **Configuration snapshots:** the protected runtime file and `.env` files
  are parsed at most once each per PHP request by `Environment`, rather than
  once for each distinct configuration key.
- **Zero on-request global download cleanup:** a bounded prune runs once per
  minute from the existing worker (not from `LevelDownloadTracker::record`).
  The per-client expiry required for correct counting stays in the transaction.
- **Opt-in Redis cache sidecar:** `docker-compose.redis-cache.yml` provides
  volatile, local-only Redis and enables the existing RedisCache for app and
  worker. The Docker PHP image now includes the Redis extension. The overlay
  is **not** active by default; compare baseline and Redis p95 before enabling
  it in production. If Redis is unavailable, CacheManager falls back to
  database cache, so availability must be checked explicitly.

### Measure p50/p95/p99 on localhost

```bash
# Reproducible health baseline. Includes a fresh TCP connection each request.
python3 tools/performance/http_latency.py \
  --url http://127.0.0.1/health --count 50 --warmup 5

# Local-only *read* workload. Supply the actual tenant Host header.
python3 tools/performance/http_latency.py \
  --url http://127.0.0.1/database/getGJLevels21.php \
  --host-header staging.example.test --method POST \
  --data 'type=0&page=0&gameVersion=22&binaryVersion=51' \
  --count 100 --warmup 10 --concurrency 2
```

Use a functioning **staging** tenant with representative non-empty levels.
A fast empty response is not a representative search measurement. The command
refuses remote hosts without `--allow-remote`, rejects credential fields and
limits concurrency. It performs fresh HTTP connections; its p95 contains
network/proxy/application time, and does **not** measure exact Geometry Dash
client acceptance or a steady-state keep-alive connection. Compare the
existing sanitized `ClientTrace` `duration_ms` for PHP handler time.

Run the same read-only workload and dataset before and after each change;
check both cold and warm cache. Avoid benchmarking account creation, level
uploads, message sends, or real user traffic.

### Optional Redis evaluation (staging only)

```bash
docker compose -f docker-compose.yml -f docker-compose.redis-cache.yml config -q
# Only after isolated staging is configured and the PHP image was built
# with the optional Redis extension:
docker compose -f docker-compose.yml -f docker-compose.redis-cache.yml \
  up -d --build redis-cache app worker
docker compose -f docker-compose.yml -f docker-compose.redis-cache.yml \
  exec -T app php -m
```

**Do not run these overlay commands on a production tenant without an approved
maintenance window and a backup.** Redis is in-memory cache only; its 128 MiB
budget should be sized to the host's available RAM. The existing DB-cache
fallback remains available and the overlay must be present on subsequent
`docker compose` invocations to preserve the chosen configuration.

## Run before production deployment

Use GitHub Actions Validate as a baseline. CI exercises MariaDB queue and
download behavior, a 700-level search reindex, and Redis key invalidation.

On a **separate staging database**, take a backup and compare before/after
metrics under identical test workloads: concurrent queued jobs per second,
download response p50/p95, `mucho_level_downloads` row count, search-index
rebuild duration and peak process RSS, cache invalidation latency, PHP-FPM
CPU/memory and GD 2.209 response wire equality. Do not benchmark destructive
rebuilds against active player data.

The official release proof remains the existing
`tests/release/v110-endpoint-gate.php`, independent of these optimizations.

## VPS rollout (once CI and staging pass)

- Confirm `git status --short` is empty; snapshot volumes/DB and note
  `git rev-parse HEAD`.
- For a development-source checkout, fast-forward from `origin/main`.
  Do not switch branches or hard reset a dirty installation.
- PHP changes are picked up by the bind mount with a short OPcache recheck.
  The OPcache INI tuning **requires rebuilding the app image**, so do this
  only in a scheduled maintenance window with an existing verified backup.
- Validate with `php tests/protocol/endpoint-perfection.php`, existing
  integration tests and native client smoke tests on staging.
- Revert to the saved Git commit and previous Docker image if regression
  measurements or client protocol checks fail.

Don't run schema migrations, use `do-release-upgrade`, or publish a stable
1.1.0 tag to apply this patch.
