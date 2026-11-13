# PHP 8: text-search acceleration and concurrent read/write measurement

**Status:** experimental tooling, **not** a VPS throughput result or player-count claim.
Scope: current PHP 8 Caddy -> PHP-FPM -> MariaDB stress stack, GD 2.209-shaped requests.
Do not run on the public server or on a database containing real players.

## 1. Search bottleneck and safe index experiment

`LevelRepository::search()` handles textual GD type=0/15 queries with
`l.name LIKE '%term%'` and `ORDER BY l.likes DESC,l.level_id DESC`.
It has to preserve arbitrary substring matching, case/collation, filters,
pagination, and the exact GD response. The existing FULLTEXT index on
`mucho_level_search_index.search_text` is **not** a drop-in replacement:
minimum token lengths, stop words and mid-word substrings can change matches.

Previous isolated 10k-level VPS data showed text-search p95 141.72 ms before
experimental likes index and 66.78 ms after it in a single uncontrolled A/B.
This alone cannot prove a universal improvement.

The new file `tools/performance/optional_text_search_index_staging.sql`
proposes a *covering, likes-ordered* secondary index on
`(is_deleted,is_unlisted,unlisted2,likes,level_id,game_version,name)`. InnoDB also stores the primary
key in secondary leaves. For substring searches the leading wildcard still
prevents direct B-tree seeking, but the exact `COUNT(*)` may scan a smaller
index without fetching `levels` rows containing LONGTEXT. The game version column is required for the real GD 2.209 COUNT query; without it MariaDB chose a full scan. The optimizer may choose an entirely different plan, and writes acquire index overhead. The earlier name-first covering-index experiment improved rare COUNT but regressed common page lookup (18.49 ms to 44.65 ms p50), so it is not proposed for deployment.

Use a clean isolated **mucho_stress** checkout with the stress stack
already running, representative synthetic data seeded and a verified backup.
Collect a read-only plan before schema changes:

```bash
docker compose -f docker-compose.stress.yml exec -T app \
  php tools/performance/level_sql_audit.php > /tmp/search-before.json
```

Only on the isolated stress database, after checking that the index does
not already exist, opt in to the index trial (no automatic migration):

```bash
docker compose -f docker-compose.stress.yml exec -T db sh -lc \
  'mariadb -uroot -p"$(cat /run/secrets/stress_db_root_password)" mucho_stress' \
  < tools/performance/optional_text_search_index_staging.sql

docker compose -f docker-compose.stress.yml exec -T app \
  php tools/performance/level_sql_audit.php > /tmp/search-after.json
```

Compare `text_search_count` and `text_search_page` under
`query_plans`, including the `game_version <= 22` predicate. Compare **exact** result IDs, total counts and GD wire
responses for names with spaces, mixed case, Unicode, wildcard characters,
short terms, mid-word substrings and missing names. Repeat same-fixture
warm/cold latency A/B measurements multiple times. Include write latency,
CPU, RSS, DB locks, disk use, and container restart/OOM counters.

Rollback on **the same staging database only**:

```sql
ALTER TABLE levels DROP INDEX idx_mc_text_visible_ranked;
```

No schema change is shipped to existing installations by default.

## 2. Concurrent HTTP tests

`tools/performance/concurrent_php8_http.py` measures separate ramp series
at concurrency 1, 2, 4, 8 (or a lower selected maximum):

- **read:** latest, literal text search, non-incrementing download and profile;
- **write:** genuine authenticated synthetic level/account comment uploads;
- **mixed:** three reads per one write, using the first supplied write fixture.

It aborts if a production-looking `.env` exists, the app container's
`DB_NAME` is not exactly `mucho_stress`, the Caddy port is not
`127.0.0.1:18080`, or the synthetic read preflight fails.
Traffic stays on loopback; the script does not spoof client IPs, change
MuchoProtect, patch application code or restart the stack.

Read-only starting point:

```bash
python3 tools/performance/concurrent_php8_http.py \
  --mode read --max-concurrency 8 --attempts 16 \
  --pause-ms 1000 --p95-limit-ms 500
```

Writes require **real working GD 2.209 credentials for throwaway stress
accounts only**, captured from successful synthetic client requests.
Create a JSON file `.stress-secrets/php8-write-fixture.json`, with
permissions 0600, of this shape:

```json
{
  "kind": "muchocore-synthetic-writes-v1",
  "jobs": [
    {
      "path": "/database/uploadGJComment21.php",
      "form": {
        "accountID": "123",
        "gjp2": "REPLACE_WITH_SYNTHETIC_GJP2",
        "levelID": "1",
        "comment": "REPLACE_WITH_VALID_GD_ENCODED_COMMENT",
        "gameVersion": "22",
        "binaryVersion": "51"
      },
      "expected": "positive_id"
    }
  ]
}
```

These are **placeholders**, not valid credentials or a working GD message
encoder. Every job must have a unique (endpoint, accountID, comment) payload:
the runner rejects duplicates so it never mistakes a duplicate-comment
rejection for write throughput. For `--mode write` and `--mode all`, provide
at least as many unique jobs as `--attempts` (16 by default). For
`--mode mixed`, provide at least `ceil(attempts/4)` unique jobs; the runner
interleaves three reads for each distinct write. Include multiple distinct
legitimate synthetic accounts if you want to study multi-user contention.
Do not commit or paste secrets into logs. For account-comment uploads, use
`/database/uploadGJAccComment20.php` and `"expected": "one"`.

Then deliberately opt in to mutations:

```bash
chmod 600 .stress-secrets/php8-write-fixture.json

python3 tools/performance/concurrent_php8_http.py \
  --mode all --max-concurrency 8 --attempts 16 \
  --pause-ms 1000 --p95-limit-ms 500 \
  --write-fixture .stress-secrets/php8-write-fixture.json \
  --allow-synthetic-writes
```

The output JSON has per-stage attempts, accepted responses, error categories,
p50/p95/p99, client-paced accepted RPS and pass/fail. Failed/blocked stages
stop escalation. A 200 response with GD `-1` is a failure, not a success.
For write/mixed stages, the runner automatically reads the total rows in
`comments` and `account_comments` from the **isolated app's PDO connection**
before and after each stage. It fails if the total inserted-row delta differs
from accepted write acknowledgements. Also inspect duplicate/error logs and
read back the new content through the GD endpoints: counts alone do not prove
content correctness. The fixture must not be shared with any other writer
while this isolated count assertion runs.

These synthetic comment uploads measure concurrent application and database
writes. They do **not** exercise write-maintenance costs of the proposed
`levels.name` index. Before adopting that index, separately measure genuine
synthetic level creation, rename/update and deletion on staging.

Monitor `docker stats`, MariaDB locks and PHP-FPM saturation concurrently.
Any MuchoProtect rate-limit rejection means the single-IP source is policy
limited; **do not** interpret it as a PHP-FPM/SQL maximum or disable
protections to get prettier numbers. Client pacing also means the reported
RPS is **not** an unbounded capacity ceiling. Raise concurrency carefully on
isolated infrastructure only.

To check the HTTP runner's response validation and parallel request handling
without Docker or database access:

```bash
python3 -m unittest tests/performance/test_concurrent_php8_http.py
```

These mocked HTTP tests validate the measurement harness, **not** MariaDB
transaction correctness or the number of real players.

## 3. Converting measured throughput to players

Do **not** equate 10,000 stored levels, 8 worker threads or 100 RPS with
10,000 active players. Determine the highest sustained mixed read/write rate
that meets a chosen p95 SLA, zero data-integrity errors, tolerable CPU/RAM,
and zero OOM/restart events; ideally reproduce it from multiple legitimate
client networks without circumventing per-user/IP protections.

With observed game-client traffic `r` requests per active player per minute,
a rough upper model is:

`active players ≈ sustainable mixed RPS × 60 / r`

This is **only a model**; the actual value depends on burstiness, read/write
mix, per-IP limits, caching, fixture size, DB contention and player behavior.
Use multiple representative traffic traces and reserve headroom. Report an
observed envelope and assumptions, never a single certified player count
from a small local benchmark.
