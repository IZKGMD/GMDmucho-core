# MuchoCore Go edition — migration in progress

The goal is a complete standalone Go edition. The native runtime now includes
accounts, level transfer and search, profiles, leaderboards, comments and
moderator comment commands, likes, messages, friends/blocks, follows/mutes,
notifications, rewards/challenges, Cloud Save, discovery, background jobs, clans,
clan quests, appearance colors, level lists, comment history, legacy renaming,
level ratings/suggestions/reports, song statistics, GD 2.209 utilities, persisted
account restrictions, abuse cases, bounded content moderation, native DM/group
threads and invitations, editor sound storage, death telemetry, level controls,
news feeds/images, profile image cropping and browser password changes.
It accesses the existing MuchoCore database directly and never invokes PHP.

**This is not yet the complete core.** See [the route inventory](ROUTES.md) for
registered handlers: all 166 canonical Application routes now have native
handlers. This denominator excludes standalone scripts and browser admin. API v2,
recovery/passkeys, plugins, browser administration and deployment/upgrade tooling
still require ports or acceptance. Real game-client acceptance and comparative load benchmarks remain
pending. The PHP edition remains the complete runtime.

All Go runtime code, tests, the database snapshot and this documentation live in
`go/`. PHP files in `testdata/` are development-only reference generators; they
are not included in the executable and are not used at runtime.

## Build and run

Requires Go 1.26+ and an initialized current MuchoCore MySQL/MariaDB database.
The Go module is separate from Composer's root `vendor/` directory.

```sh
cd go
go mod download
go build -o bin/muchocore ./cmd/muchocore
export MUCHO_GO_DSN='muchocore:YOUR_PASSWORD@tcp(127.0.0.1:3306)/muchocore?charset=utf8mb4'
export MUCHO_GO_ADDR='127.0.0.1:8081'
./bin/muchocore
```

The application database account needs SELECT, INSERT, UPDATE and DELETE rights
for the implemented modules, including the shared security and job tables. The
runtime reads environment variables directly and does not load PHP configuration
or `.env`. Startup connects to the database but never runs DDL.

| Variable | Meaning |
| --- | --- |
| `MUCHO_GO_DSN` | Required MySQL driver DSN; supports driver TLS settings |
| `MUCHO_GO_ADDR` | HTTP address; defaults to `127.0.0.1:8081` |
| `MUCHO_GO_COOKIE_SECURE` | Set to `1` behind an HTTPS reverse proxy to require secure browser cookies |
| `MUCHO_PUBLIC_ASSET_BASE` | Optional base URL for player account page assets; bundled assets work without PHP |
| `MUCHO_ACCOUNT_RECOVERY_PATH` | Recovery page link; its PHP default still requires a recovery service while that port is pending |
| `MUCHO_ACCOUNT_URL` | Account service URL returned to game clients |
| `MUCHO_CUSTOM_CONTENT_URL` | Custom content URL returned to game clients |
| `MUCHO_CLOUDSAVE_KEY_FILE` | Absolute path to the existing base64-encoded 32-byte Cloud Save key; default `/var/lib/muchocore/cloudsave.key` |
| `MUCHO_TRUSTED_PROXY_CIDRS` | Explicit trusted proxy IPs/CIDRs, separated by spaces or commas; follows the PHP trust rules |
| `MUCHO_PROTECT` | Protection enabled by default; `0`, `false`, `off`, `no` disable it |
| `MUCHO_ANTICHEAT_QUARANTINE` | Opt-in filtering of suspicious score records |
| `MUCHO_DOWNLOAD_HMAC_KEY` | Stable download deduplication secret; otherwise derived from the DB password, or random per process |
| `MUCHO_DOWNLOAD_DEDUP_SECONDS` | Deduplication window; default 300, bounded to 30–604800 seconds |
| `MUCHO_WEBHOOK_URL` | Optional webhook destination |
| `MUCHO_WEBHOOK_EVENTS` | Comma-separated events, or `*` |
| `MUCHO_WEBHOOK_SECRET` | Optional HMAC-SHA256 webhook signing secret |

Preserve the existing Cloud Save key. The server never generates or replaces a
key implicitly. PHP bcrypt hashes, GJP/GJP2 and bounded Argon2i/Argon2id hashes
are supported. Standard authentication never grants access on account ID and IP
alone; credential-less legacy 1.9 sessions also require the device UDID.

```sh
curl -fsS http://127.0.0.1:8081/health
curl -fsS http://127.0.0.1:8081/ready
curl -fsS -X POST http://127.0.0.1:8081/a/database/getGJSongInfo.php -d 'songID=1'
```

`/health` and `checkIfServerOnline.php` return liveness `1`. `/ready` checks the
DB and returns HTTP 503 with `-1` on failure. Legacy URL aliases and prefixes are
accepted without POST redirects. Unsupported game routes return `-1`. Requests
have bounded body sizes, database deadlines and a bounded connection pool.

## New database and worker

For a **new empty database only**, use a separate account with DDL privileges:

```sh
./bin/muchocore --init-db
```

The embedded snapshot contains all 96 tables and migration defaults generated
from the existing PHP migrations. Initialization acquires the shared migration
lock and refuses any non-empty database. It is not an existing-installation
upgrade command and does not create a browser administrator account.

The native worker supports indexing, signed webhooks and maintenance cleanup:

```sh
./bin/muchocore --worker
./bin/muchocore --worker --loop --sleep=2
```

One-shot mode processes at most one job. Leases are fenced by worker ID and
attempt number; expired leases are recovered and failures retry up to five
attempts. SIGINT/SIGTERM stop polling and allow acknowledgements to finish.
Webhook delivery requires a direct HTTP 2xx response; redirects are not followed.

## Validation

```sh
cd go
go test -race ./...
go vet ./...
```

Protocol and image reference vectors are committed. Regenerate them from the repo root
(the image generator requires PHP GD with JPEG/PNG support):

```sh
php go/testdata/protocol-fixtures.php > go/testdata/protocol.json
php go/testdata/profile-image-fixtures.php > go/testdata/profile-images.json
python3 go/tools/route-coverage.py --check
```

The core differential test requires a **fresh disposable** MariaDB instance with
passwordless root and empty `muchocore_go_core_test` and
`muchocore_go_reference` schemas. The reference schema is created by the script;
never reuse an installation database. From the repo root:

```sh
mkdir -p work
export MUCHO_GO_PHP_TEST_DSN='mysql:host=127.0.0.1;dbname=muchocore_go_core_test;charset=utf8mb4'
export MUCHO_GO_TEST_KEY_FILE="$PWD/work/cloudsave-test.key"
export MUCHO_GO_CORE_FIXTURES="$PWD/work/core-reference.json"
php go/testdata/core-reference.php > work/core-reference.log

cd go
MUCHO_GO_CORE_DSN='root@tcp(127.0.0.1:3306)/muchocore_go_core_test?charset=utf8mb4&timestamp=1700000000' \
  go test -race ./...
```

The generator applies every real PHP migration, seeds synthetic accounts and a
PHP-encrypted save, clones the baseline and records PHP controller responses.
Go replays the same stateful scenario on the untouched baseline. Use fresh test
schemas for each run. The tests also cover tampered saves, concurrent rate
limits, clan capacity and quest score serialization, worker services and lease ownership/recovery and complete native schema structure
against the current PHP schema. The native browser test checks CSRF, password
changes, recovery/session revocation and bundled assets. GD image references
compare decoded pixels and crop geometry; Go and PHP JPEG bytes can differ.
Stored PHP JPEGs remain readable without changes. GitHub Actions requires these
DB tests and regenerates image references using its installed GD library.

To refresh the initial schema snapshot, set `MUCHO_GO_EXPORT_SCHEMA` to the
absolute path of `go/internal/schema/schema.json` while bootstrapping a fresh
test schema. No installation data is included in that snapshot.
