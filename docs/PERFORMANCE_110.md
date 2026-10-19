# MuchoCore performance pass — October 19, 2026

This is a **targeted core optimization**, not a measured global speedup or a
full 1.1.0 release certification. It changes internals only; Geometry Dash
request/response wire formats and the stable-release marker remain unchanged.

## Bottlenecks addressed

| Area | Before | Now | Primary guard |
|---|---|---|---|
| Background queue | Workers wait on the oldest locked job | `FOR UPDATE SKIP LOCKED` reserves other available jobs on MariaDB 10.6+; older engines fall back | Isolated concurrent MariaDB worker test |
| Download deduplication | Every player download scans/deletes *all* expired rows for its level | Delete only expired slots for the requesting `(level_id, client_hash)` through the existing composite unique index | Two-download-per-client, expiry reuse and counter checks |
| Deduplication storage | Popular levels can accumulate expiry rows until a download reaps all of them | One-in-128 requests performs a bounded expiry cleanup (`LIMIT 256`) outside the main transaction using the existing timestamp index | MariaDB integration plus existing dedupe contract |
| Search indexing | One `fetchAll()` loads every ID into PHP | Fixed 256-row keyset batches, a high-water ID and no increasing OFFSET | 700-level MariaDB integration |
| Redis invalidation | Prefix invalidation uses synchronous `DEL` for all matches | `UNLINK` asynchronously reclaims payloads when supported, with `DEL` fallback | Literal wildcard + multi-batch Redis test |
| PHP runtime | Distribution-default OPcache limits | Conservative cache sizing and realpath cache; **timestamps remain validated** | Docker build + existing PHP and routing tests |

## What this does NOT do

- No claim of a 10x improvement, of lower p95, or of solved network/game latency.
- No database migrations or indexes added: existing indexes are reused.
- No change to GD protocol framing or authentication.
- No certification of previously unverified GD 2.209 endpoints.
- No production database benchmark has been run.

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
