# PHP 8 VPS isolated stress verification — 2026-11-13

**Scope:** experimental PHP 8.3.36 / MariaDB 11.4 / Caddy 2.10 stress
stack on the user's VPS, with production simultaneously running.
All performance figures are **observations**, not release or player-capacity certificates.
Evidence: [VPS Bridge control issue #205](https://github.com/IZKGMD/GMDmucho-core/issues/205).
All commands were dispatched through the existing owner-only GitHub Actions bridge.

## Environment and safety

- Isolated Git checkout: `/opt/mucho-stress`, PR #302 branch.
- Isolated MariaDB schema: `mucho_stress` (98 schema tables after bootstrap);
  10,000 generated synthetic levels; no real-player data copied.
- Local service: `127.0.0.1:18080` exclusively, not reachable on the public interface.
- Separate local test application image uses PHP 8.3.36, not the production app image.
- Host: 961 MiB RAM, 2,047 MiB swap, ~47 GiB disk available before setup.
- Explicit container memory limits: MariaDB 256 MiB; PHP app 192 MiB; Caddy 64 MiB.
  Stress services have `restart: no` in a local untracked override.
- Production `/opt/mucho-core` and acceptance stack were not redeployed,
  migrated or restarted. No production index changes.
- The stress fixture's authentication secret and all write payloads stay in
  root-only local `.stress-secrets/`; no credentials in bridge messages or reports.
- Observed healthy stress containers and production containers with
  `OOMKilled=false`, restart count 0 after the tests.

## Synthetic search scale

| Item | Observed result |
|---|---:|
| 1,000 fixture seed (999 new rows) | 1.28 s |
| 1,000 search-index rebuild | 3.19 s |
| 10,000 fixture seed (+9,000 rows) | 4.66 s |
| 10,000 search-index rebuild | 31.51 s |

## Bounded parallel GD 2.209 reads (HTTP end-to-end p95)

Each row tested 8 or 16 requests and rotated latest-level search,
nonempty text search, `inc=0` level download and public profile.
Results include Caddy/PHP-FPM/PDO/MariaDB and cache state; they are
*not* isolated text-search timings or sustained maximum RPS.

| Concurrent clients/requests | 1k levels, 8 samples | 10k no candidate, 16 samples | 10k name-first candidate, 16 samples | 10k ranked candidate, 16 samples |
|---|---:|---:|---:|---:|
| 1 | 76.25 ms | 104.73 ms | 88.74 ms | 156.49 ms |
| 2 | 107.03 ms | 182.03 ms | 431.04 ms | 197.60 ms |
| 4 | 204.73 ms | 210.33 ms | 178.88 ms | 160.63 ms |
| 8 | 138.38 ms | 190.13 ms | 578.49 ms | 178.06 ms |

All 32/32 (1k), 64/64 (10k baseline), 64/64 (name-first), and 64/64
(ranked) read responses passed content validation; zero HTTP failures
in these stages. Notice the mixed route p95 varies substantially.
The new ranked index is **not** proven to improve overall p95 consistently.

Evidence: [1k read](https://github.com/IZKGMD/GMDmucho-core/actions/runs/43401757233),
[10k baseline](https://github.com/IZKGMD/GMDmucho-core/actions/runs/43402171729),
[name-first](https://github.com/IZKGMD/GMDmucho-core/actions/runs/43402577324),
[ranked](https://github.com/IZKGMD/GMDmucho-core/actions/runs/43404369301).

## SQL text-search A/B microbenchmarks

The first candidate `(is_deleted,is_unlisted,unlisted2,name,game_version)`
did make the common COUNT covering, but regressed the page query
(18.493 ms baseline → 44.647 ms index-forced p50).

A second optional candidate was tested **on isolated staging only**:

```sql
CREATE INDEX idx_mc_text_visible_ranked
    ON levels (is_deleted,is_unlisted,unlisted2,likes,level_id,game_version,name);
```

Its `EXPLAIN` plan for a representative GD 2.209 search
(`name LIKE '%Synthetic Stress Level%'`, `game_version <= 22`,
visible rows, `ORDER BY likes DESC, level_id DESC LIMIT 10`)
uses `idx_mc_text_visible_ranked` with `Using where; Using index`
and **no filesort**. The original query was a table scan with filesort.

Interleaved 25-sample SQL-only comparison (same fixture, identical results):

| SQL query / p50 | Baseline (IGNORE INDEX) | Ranked (FORCE INDEX) | Default optimizer |
|---|---:|---:|---:|
| Frequent text page | 19.936 ms | 0.449 ms | 0.399 ms |
| Frequent text COUNT | 21.285 ms | 18.901 ms | 23.285 ms |
| Rare text COUNT | 22.306 ms | 15.038 ms | 12.026 ms |

Frequent text page p95: 24.621 ms baseline vs 0.748 ms forced candidate.
**This is not a 50x faster entire PHP server.** COUNT p95 for frequent text
actually rose (48.671 → 54.746 ms forced), and extra indexes increase
write maintenance overhead. More independent samples and level-create/update
write benchmarking are required before production use.

Four representative GD 2.209 text-search wire responses had exactly the
same SHA-256 hashes before any index and after the ranked index. No
client-visible protocol changes were observed.

Evidence: [real-query predicate check](https://github.com/IZKGMD/GMDmucho-core/actions/runs/43403593054),
[ranked microbench](https://github.com/IZKGMD/GMDmucho-core/actions/runs/43404238374),
[wire parity](https://github.com/IZKGMD/GMDmucho-core/actions/runs/43404608872).

## Authenticated parallel HTTP writes and mixed workload

A new guarded synthetic fixture generator issued a genuine GJP2 credential
to the existing `StressRobot` account *within the isolated stress DB only*.
The credential was hashed at rest and never printed. GD 2.209 comment
payloads were Base64-url encoded and passed the actual app authentication
path. Insert acknowledgments were compared to database row-count deltas.

| Scenario | Accepted | DB insert delta | p95 |
|---|---:|---:|---:|
| Writes, concurrency 1 | 8/8 | 8 | 584.64 ms |
| Writes, concurrency 2 | 7/8 | 7 | 483.77 ms |
| Mixed, concurrency 1 | 8/8 (2 writes) | 2 | 391.92 ms |
| Mixed, concurrency 2 | 8/8 (2 writes) | 2 | 643.44 ms |
| Mixed, concurrency 4 | 8/8 (2 writes) | 2 | 379.49 ms |
| Mixed, concurrency 8 | 8/8 (2 writes) | 2 | 798.38 ms |

One attempted write returned an invalid GD response (not counted as success);
the actual cause was not instrumented. MuchoProtect's built-in
`uploadgjcomment21` policy allows 8 requests per 10-second burst window,
which is a plausible explanation but **not proven**.
Do not weaken protection to make benchmark numbers look better.

Total: **23 accepted authenticated comment writes and 23 actual SQL inserts**;
0 missing acknowledged writes. Read-only verification found 23 synthetic
comment rows on the expected account and level. Mixed workload passed 32/32
requests in four 8-request stages.

Evidence: [write stages](https://github.com/IZKGMD/GMDmucho-core/actions/runs/43403074118),
[mixed](https://github.com/IZKGMD/GMDmucho-core/actions/runs/43403310054),
[final data integrity](https://github.com/IZKGMD/GMDmucho-core/actions/runs/43404608872).

## Limits and next release gates

1. Keep the ranked index **optional staging-only**, never an automatic
   installation migration. Measure *real synthetic level INSERT/UPDATE/DELETE*
   latency with and without the index before considering rollout.
2. Repeat longer HTTP tests with stable SQL fixtures, explicit
   cache hot/cold conditions, deterministic request mix and error attribution.
3. Differentiate MuchoProtect policy rejections from real PHP-FPM/MariaDB
   failures; preserve all protections and never spoof source IPs.
4. Collect per-stage CPU, FPM busy workers, InnoDB row locks, OOM and
   memory high-water marks, not just pre/post snapshots.
5. Model **active players** only after measuring legitimate per-player requests
   per minute and a sustained protected workload. Neither short 8/16-request
   concurrency stages nor one observed burst RPS proves a player limit.

**PR #302 remains DRAFT; do not merge/deploy this index solely on these results.**
