# MuchoCore — SQL plans and staged index candidates (October 20, 2026)

## Evidence from real VPS (MariaDB 11.4.13)

Read-only `tools/performance/level_sql_audit.php` showed:

| Pattern | Original index | EXPLAIN |
|---|---|---|
| Visible levels ordered by likes | none | ALL; Using filesort |
| Visible levels ordered by downloads | none | ALL; Using filesort |
| Visible featured levels ordered by updated_at | none | ALL; Using filesort |
| Visible levels ordered by created_at | idx_levels_created | index; Using where |
| Visible-level count | none | ALL; Using where |

**Important:** All scans had `rows_estimate: 1`, which is an optimizer
estimate, **not an exact record count**. It is entirely reasonable for
MariaDB to choose a table scan on a tiny table; none of these plans by
itself demonstrates a measurable production bottleneck. Current player data
were not exported or modified.

## Optional experimental indexes

`tools/performance/optional_level_indexes_staging.sql` defines:

- `(is_deleted,is_unlisted,likes,level_id)`
- `(is_deleted,is_unlisted,downloads,level_id)`
- `(is_deleted,is_unlisted,updated_at,level_id)`

These match filtering and all-descending sorting patterns used by the game.
The optimizer can still choose scans when appropriate, especially on small
databases. `featured > 0` remains a filter, not a leading index
component: a range on featured would break the ordered updated-at prefix.

**This SQL file is not a migration, not applied on startup and not shipped
into any automatic production schema upgrade.** Each extra index costs
disk/RAM and adds write/maintenance overhead. Do not add to the user's
~1 GiB VPS without representative staging A/B read AND write benchmarks and
a verified backup.

The isolated MariaDB integration uses 10,000 deterministic fixture rows in
a temporary database, validates identical sorted IDs before/after index
creation, and validates each forced-index plan is usable without filesort.
The test does **not** prove lower p95 or that MariaDB will select the index
in a real production distribution.

## Safe next VPS measurement

A single read-only exact count clarifies whether the optimizer's
`rows_estimate: 1` describes a genuinely tiny levels table:

```bash
cd /opt/mucho-core
docker compose exec -T db sh -lc \
  'mariadb -u root -p"$(cat /run/secrets/db_root_password)" \
   -D "$MARIADB_DATABASE" -N -e \
   "SELECT COUNT(*) AS level_count,
           SUM(is_deleted=0 AND is_unlisted=0) AS visible_count
    FROM levels"'
```

Never print passwords, database dumps or account details. Counting once
on the current tiny database is low risk, but a full scan on large DBs
can be expensive; repeat only on staging when scaling up.

## Conditions before production

1. Run full GitHub Validate CI and isolated MariaDB index fixture.
2. Collect representative p50/p95 of real, nonempty requests on the VPS and
   on isolated staging with equal data and cache state.
3. Inspect query plans at 1k/10k/100k rows, then consider partial adoption of
   candidates; measure upload/like/download write rates as well.
4. No performance multiplier or stable release claim without controlled data.
