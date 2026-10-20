# 1.1.0 P0 native client capture: test tenant only

This captures **evidence**, not a release certificate. Route observations,
HTTP 200 and server-duration p95 cannot prove native parsing, response framing,
authorization, DB correctness, or concurrency by themselves.

## Prerequisites

- A separately accessible **testgdps** tenant, correctly proxied to
  `testgdps-app`. The regular production Caddy config does **not** expose
  `testgdps-app` by default.
- `docker-compose.test.yml` and its test-only secrets configured.
- A test database, disposable test accounts, authorized Windows and Android
  clients patched to the **staging** URL, and tester consent.
- Do not capture real users' private traffic. Never commit raw NDJSON logs,
  credentials, tokens, IPs, UDIDs, usernames, response bodies or messages.

## Enable tracing on test app only

```sh
cd /opt/mucho-core
docker compose -f docker-compose.yml -f docker-compose.test.yml \
  -f docker-compose.trace-test.yml config -q

docker compose -f docker-compose.yml -f docker-compose.test.yml \
  -f docker-compose.trace-test.yml up -d --no-deps --force-recreate testgdps-app

docker compose -f docker-compose.yml -f docker-compose.test.yml \
  -f docker-compose.trace-test.yml exec -T testgdps-app \
  printenv MUCHO_CLIENT_TRACE
```

Expected environment output: `1`. This recreates **testgdps-app only**, and
will fail if the isolated test database is not configured or running. It does
not enable tracing on production `app` and does not configure public routing.

Using authorized test accounts, exercise the six P0 workflows from an
unmodified or correctly staging-patched GD 2.209 client (Windows and Android):

1. Log in (`account.login`).
2. Search levels (`levels.search`).
3. Upload a disposable test level (`levels.upload`).
4. Download it (`levels.download`).
5. Open user leaderboard (`users.leaderboard`).
6. Read level comments (`comments.read`).

Test correct success and error conditions separately. A client UI must accept
responses before calling the corresponding native wire format verified.

## Inspect without publishing traces

```sh
cd /opt/mucho-core

docker compose -f docker-compose.yml -f docker-compose.test.yml \
  -f docker-compose.trace-test.yml exec -T testgdps-app \
  php bin/mucho-gd2209-trace-report.php --min-binary=51

python3 tools/client/client-trace-summary.py \
  --input storage/testgdps-client-trace.ndjson \
  --output /root/mucho-gd2209-p0-summary.json \
  --expected-family 2.2 --binary-version 51 --game-version 22

python3 tools/release/gd2209_native_evidence.py \
  --summary /root/mucho-gd2209-p0-summary.json \
  --fail-on-missing

python3 tools/release/v110_p0_audit.py
```

Use exact client-version filters for GD **2.209**: `--min-binary=51` also includes
newer builds and therefore cannot isolate `binaryVersion=51`. Both exact filters
apply to **each request**, and an empty matching capture now fails rather than
creating apparent evidence. If you intentionally check a later build, record
its actual binary version and separate the resulting evidence. The
`mucho-gd2209-trace-report.php --min-binary=51` command is a broad exploratory
overview, not the exact-version release evidence.

The `gd2209_native_evidence.py` tool reports whether the **six P0 routes**
were observed with `gameVersion=22` / `binaryVersion=51` and matching
`2.2` client-family metadata, taking aliases like
`/a/database/getGJLevels21.php` into account. It can signal missing
observations and preliminary timing sample counts but **cannot prove successful
game-client parsing, data integrity, authentication, or native acceptance**.
`--fail-on-missing` returns nonzero if any P0 route was not observed; its
success still is not a stable-release green light.

### Record the native client's outcome separately

For **both Windows and Android GD 2.209**, use only throwaway accounts and
test levels. For each P0 action, record the exact game build, test tenant,
UTC date, observed endpoint, in-game outcome, negative-case outcome, and any
deserialization/UI errors in a privacy-reviewed report outside the public
repository. Actions: log in and reject a wrong password; search and open
search results; upload a level and reject unauthorized changes; download and
play that level; display leaderboard entries; read comments and empty pages.
Evidence from request logs alone must be marked *route observed*, not *passed*.

The new summary records `binary_versions`, `game_versions` and observed
`server_duration_p95_ms` **per endpoint**; an older global version list
cannot be credited to a different endpoint. `server_duration_sample_count`
shows how many requests had valid nonnegative durations. The p95 is a nearest-
rank observation of *server handling time*, not full network round-trip
latency or a production SLA. At least 30 samples permit a preliminary
diagnostic; signoff also requires reference dataset, reproducibility, accepted
client responses and a justified threshold. Do not copy this JSON into
`tests/client-fixtures/` blindly: evidence needs privacy review and provenance.

The P0 audit intentionally keeps registry statuses pending until independent
evidence is reviewed. It does not scan the out-of-tree staging summary by
default. Only a vetted, explicitly accepted fixture belongs in the repository.

## Disable test tracing immediately afterward

```sh
cd /opt/mucho-core
docker compose -f docker-compose.yml -f docker-compose.test.yml \
  up -d --no-deps --force-recreate testgdps-app
```

Verify test app has `MUCHO_CLIENT_TRACE=0` again. Protect and then securely
remove raw trace files once evidence has been reviewed. Do not delete the
production `storage/client-trace.ndjson` or any unrelated files.

The stable release gate remains `php tests/release/v110-endpoint-gate.php`.
Do not add `.release/v1.1.0.ready` before all required native evidence and
quality checks pass.
