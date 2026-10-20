# MuchoCore 1.1 Performance Phase 2 — level-list hot paths

All changes on `perf/global-core-20261019`, reviewed in PR #136. No
automatic database migration and no changes to GD response syntax.

## Changes

1. **Canonical public search cache keys** in `LevelService`:
   Only request fields read by `LevelRepository` contribute to the cache
   identity. UDID, UUID, GJP, and unknown transport fields cannot split an
   otherwise identical public cache entry. Preserve effective game version,
   **binaryVersion** (GD 2.209 timing fields), normalized search, pagination,
   and all supported search filters. Excluded private request types remain
   uncached. Cache prefix invalidation remains unchanged.

2. **Precise count only when needed** in `LevelRepository`:
   For `offset=0`, no daily/weekly/event join, and fewer than the requested
   page length, the result array is the complete result set and its count is
   exact; omit the additional `COUNT(*)` SQL call. Full first pages and
   any later page still perform the exact count. Rotation/history joins also
   retain the previous count query to preserve their possible multiplicity.

3. **Bounded SQL audits**: `tools/performance/level_sql_audit.php` shows
   `SHOW INDEX` and fixed `EXPLAIN` plans for popular, downloads, recent,
   featured, and visible-count operations. It never runs data-changing queries
   and doesn't collect player data. The reported rows are **optimizer
   estimates, not timings**.

## Integration protections

- Public cache test uses two different client identifiers and shuffled request
  fields to assert the same cached response, then checks that GD 2.209 vs
  older binary versions and difficulty filters remain independent.
- MariaDB integration checks a one-row numeric search uses one SELECT, an empty
  search has zero total, and a multi-page result still reports exact total.
- Existing role-authenticated GD 2.209 queue and client wire tests remain active.

## Safe VPS read-only diagnostic without checking out this branch

From `/opt/mucho-core` (current production revision stays unchanged):

```bash
git fetch origin perf/global-core-20261019
git show FETCH_HEAD:tools/performance/level_sql_audit.php \
  | docker compose exec -T app php > /tmp/muchocore-level-index-audit.json
python3 -m json.tool /tmp/muchocore-level-index-audit.json
```

This requires the standard PHP application container and its **existing**
database credentials. It reads only schema metadata and EXPLAIN plans.
Do not post files containing credentials; this report does not deliberately
include credentials or player rows.

### Current baseline from October 20, 2026 (user VPS)

- `main` revision: `247cd0a` (before PR #136).
- Valid `getGJLevels21` results, 50/50 success with pacing.
- p50: 29.197 ms / 26.836 ms on successive runs.
- p95: 50.418 ms / 40.106 ms on successive runs.
- These were fresh-connection end-to-end local HTTP measurements, not pure
  PHP/SQL times. 1.7 RPS was set by `--interval-ms 600`, not actual capacity.

No before/after Phase 2 p95 or performance multiplier has been verified yet.

## Next checks before merging or deploying

1. Review CI on the PR and collect the read-only SQL audit on staging.
2. Compare `main` vs this exact PR SHA on isolated staging with the same
   representative database snapshot, cache warmness and IP pacing.
3. Compare query counts, p50/p95/p99 and accepted GD 2.209 wire responses.
4. Only propose composite level indexes if the query plan confirms a filesort
   or table scan on a representative data set; benchmark maintenance overhead.
5. Merge after the staging verification. Avoid rebuilding a live Docker image,
   switching production branches, or altering protection settings to benchmark.
