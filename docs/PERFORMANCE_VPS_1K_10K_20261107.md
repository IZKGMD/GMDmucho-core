# MuchoCore isolated VPS 1,000 → 10,000 level baseline (2026-11-07)

**Status:** Measured on a disposable staging VPS, not a production GDPS. Tested PHP 8 runtime, GD 2.209-shaped read requests, MariaDB 11.4 and Caddy on `127.0.0.1:18080`.

## Environment and methodology

- Available RAM: 961 MiB, swap: 2047 MiB, disk: 58 GiB.
- Docker services: `muchocore-stress-app-1`, `muchocore-stress-db-1`, `muchocore-stress-caddy-1`.
- Baseline source: `main` before search-index prepare refactor (revision `e298eb2`).
- The staged workload is `tools/performance/run_stress_scale.sh`: 1,000 levels followed by 10,000 in a synthetic `mucho_stress` database. It seeds deterministic levels and rebuilds the search index before each run.
- Per fixture: 90 seconds, 201 successful requests, no errors, five sequential read scenarios rotated at a 450 ms global start interval (≈2.23 requests/sec). This rate is **client-paced**, not max throughput.
- No container OOM kills or restarts reported during these runs. No production data, real account credentials, synthetic IP forwarding or public client traffic was used.

## Before index: p95 end-to-end latency (ms)

| Read route | 1,000 levels | 10,000 levels |
| --- | ---: | ---: |
| Latest levels | 52.46 | 99.54 |
| Level ID search | 59.57 | 61.84 |
| Text search | 69.12 | 141.72 |
| Level download (`inc=0`) | 52.01 | 51.98 |
| User profile | 79.50 | 83.93 |

This comparison confirms a scale-related regression in text search and latest-level list for this synthetic fixture. Exact SQL `EXPLAIN` on the **10,000-row database** showed `l` access as `ALL`, estimated `9999` rows and `Using where; Using filesort` on the latest and text-search queries.

Evidence: [scale runner output](https://github.com/IZKGMD/GMDmucho-core/actions/runs/42397832391), [parsed reports and container OOM/restarts](https://github.com/IZKGMD/GMDmucho-core/actions/runs/42397970183), [pre-index query plans](https://github.com/IZKGMD/GMDmucho-core/actions/runs/42398144505).

## Optimizations and verification

### 1. Reuse prepared SQL statements when rebuilding search index

[PR #210](https://github.com/IZKGMD/GMDmucho-core/pull/210), merged to `main` as `aa4fcb9`, reuses a single native PDO `INSERT ... ON DUPLICATE KEY UPDATE` prepare across keyset rebuild batches rather than preparing the same statement for every level. It updates both the PHP8 source and PHP7 compiled compatibility class; MariaDB integration now checks the session `Com_stmt_prepare` counter on 300 rows.

- Initial 10k fixture rebuild: **32.48 s**.
- Repeat 10k rebuild on revised source: **26.46 s** (observed 18.5% shorter).

These timings are **not a rigorously controlled A/B**: the initial run followed 9,000 inserts, whereas the repeat operated on an already populated/indexed and warmed database. The prepared-statement count assertion is the more direct proof that repeated SQL preparation was removed. Full CI passed, including PHP7 native feature integration, Go native port and Validate.

Evidence: [repeat index rebuild](https://github.com/IZKGMD/GMDmucho-core/actions/runs/42398285148).

### 2. Trial the existing optional visible-level likes index

On the isolated **10k staging database only**, applied the pre-existing candidate from `tools/performance/optional_level_indexes_staging.sql`:

```sql
ALTER TABLE levels
  ADD INDEX idx_mc_visible_likes (is_deleted, is_unlisted, likes, level_id);
```

Afterward MariaDB selected `idx_mc_visible_likes` automatically for a representative latest-level search (`type=range`, `Using where`, **no filesort**).

For the same 10k synthetic fixture, a new 90-second mixed-GD-read run produced the following:

| Read route | Before index p95 (ms) | After index p95 (ms) | Difference |
| --- | ---: | ---: | ---: |
| Latest levels | 99.54 | 79.52 | −20.1% |
| Level ID search | 61.84 | 66.29 | +7.2% |
| Text search | 141.72 | 66.78 | −52.9% |
| Level download | 51.98 | 65.19 | +25.4% |
| User profile | 83.93 | 75.18 | −10.4% |

All **201 of 201 requests** were valid, with zero errors. The indexed routes improved in this run. Unrelated routes fluctuated in both directions, so the data **does not** support an overall 53% core speedup claim. The runs are single samples and cache/system variance was not independently controlled.

Evidence: [post-index EXPLAIN](https://github.com/IZKGMD/GMDmucho-core/actions/runs/42398409779), [post-index 90 s result](https://github.com/IZKGMD/GMDmucho-core/actions/runs/42398599234).

### Index deployment policy

The index was intentionally **not** automatically added to existing installations or the `003_levels` migration. Creating indexes on a live table can lock writes and consume additional I/O, memory and disk, especially on limited hosting. Operators should back up and perform a controlled before/after test on a staging copy before opting in. The existing SQL file also contains other candidate indexes for downloads/updated sorts; **those were not tested in these VPS measurements**.

To undo **only this trial index** on the isolated staging database:

```sql
ALTER TABLE levels DROP INDEX idx_mc_visible_likes;
```

This does not remove player levels; it removes the extra index, possibly causing a slower query plan.

## Limitations and next release gates

This is not proof of support for 10,000 simultaneous clients, real game-client certification, login/upload authorization, mixed writes or rate-limit scalability. Performance numbers apply only to this isolated PHP8 configuration. PHP7 free shared-hosting must be tested separately. Next practical checks: representative fixture diversity, multiple cold/warm A/B samples, PHP-FPM/MariaDB resource attribution, authenticated GD2.209 upload/download and data-integrity acceptance, then a live client exercise.
