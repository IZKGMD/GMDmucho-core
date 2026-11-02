# MuchoCore Go edition — migration in progress

The goal is a complete standalone Go edition. The native runtime now includes
accounts, level transfer and search, profiles, leaderboards, comments and
moderator comment commands, likes, messages, friends/blocks, follows/mutes,
notifications, rewards/challenges, Cloud Save, discovery, background jobs, clans,
clan quests, appearance colors, level lists, comment history, legacy renaming,
level ratings/suggestions/reports, song statistics, GD 2.209 utilities, persisted
account restrictions, abuse cases, bounded content moderation, native DM/group
threads and invitations, editor sound storage, death telemetry, level controls,
news feeds/images, profile image cropping, browser password changes and one-time
account recovery with optional Turnstile and native SMTP delivery, public API v2
profiles/presence/dashboard/client configuration/branding/music, MP3 uploads and
the administrator client API with TOTP, token revocation, RBAC and audited writes,
and a native browser administration workspace.
It accesses the existing MuchoCore database directly and never invokes PHP.

**This is not yet the complete core.** See [the route inventory](ROUTES.md) for
registered handlers: all 166 canonical Application routes now have native
handlers. This denominator excludes standalone scripts and browser admin. All 10
standalone API v2 endpoints also have native handlers. Administrator passkeys,
plugins, the remaining administration modules and deployment/upgrade tooling
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
| `MUCHO_MUSIC_DIR` | Music upload/download directory; defaults to the PHP-compatible `/var/www/mucho-core/storage/music-public`; must be writable by the server user |
| `MUCHO_SERVER_NAME` | Fallback public server name when branding data is unavailable |
| `MUCHO_GO_COOKIE_SECURE` | Set to `1` behind an HTTPS reverse proxy to require secure browser cookies |
| `MUCHO_PUBLIC_ASSET_BASE` | Optional base URL for player account page assets; bundled assets work without PHP |
| `MUCHO_ACCOUNT_RECOVERY_PATH` | Optional recovery link for account management; defaults to the native `/api/api/accounts/lostusername.php` route |
| `MUCHO_PUBLIC_URL` | Trusted HTTPS origin used for recovery links; falls back to `MUCHO_ACCOUNT_URL`, then `https://localhost`; never taken from the Host header |
| `MUCHO_SMTP_HOST`, `MUCHO_SMTP_PORT` | Recovery SMTP server; port defaults to 587 |
| `MUCHO_SMTP_FROM` | Sender address; falls back to `MUCHO_RECOVERY_FROM` |
| `MUCHO_SMTP_USER`, `MUCHO_SMTP_PASSWORD` | Optional SMTP authentication |
| `MUCHO_SMTP_TLS` | Enable implicit TLS; automatically enabled on port 465; other remote servers must support STARTTLS |
| `TURNSTILE_SITEKEY`, `TURNSTILE_SECRET` | Enable the recovery security challenge when both are configured |
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
upgrade command. Then create the first administrator explicitly:

```sh
# Read a 12–72 byte password from a private file (one line).
./bin/muchocore --create-admin --admin-user=Owner --admin-password-file=/run/secrets/mucho-admin-password
# Alternatively pipe the password through standard input; terminal echo is refused.
# The password is never accepted as a command-line argument.
```

Bootstrap refuses the database if any administrator already exists. Concurrent
bootstrap attempts cannot create multiple first owners. It neither changes
existing credentials nor imports PHP bootstrap secrets. Open `/admin/` after
starting the HTTP server. Existing PHP administrator password hashes and TOTP
secrets work without conversion.

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
Recovery tests use a local SMTP test receiver and a fake mail adapter; no external
email is sent. They cover single-use concurrent resets, expiry, stored token
hashes, Host-header isolation, CSRF and legacy session revocation. API v2 tests cover presence privacy, token authentication, concurrent heartbeat
limits, security responses/metrics, MP3 approval state, upload cooldowns, file
rollback on SQL failure and byte-range downloads. PHP version comparisons have
400 reference cases. Actual SMTP delivery requires operator configuration. Without SMTP, recovery requests retain
the generic response and log an unavailable-delivery message.

To refresh the initial schema snapshot, set `MUCHO_GO_EXPORT_SCHEMA` to the
absolute path of `go/internal/schema/schema.json` while bootstrapping a fresh
test schema. No installation data is included in that snapshot.

Administrator client writes and their audit records commit in one transaction.
Game role changes update both role representations to prevent legacy moderator
privileges surviving demotion. Level edits also recalculate creator points and
refresh the search index. Tests cover custom permission roles, immediate permission
revocation, strict JSON fields, concurrent login failure limits and rollback when
audit storage fails.
The native `/admin/` workspace supports overview counters, player search,
ban/unban, game-role and profile edits, level search and rating/deletion edits,
custom permission roles, administrator invitations/status/role changes, combined
legacy and API audit history, password changes, TOTP enrollment and one-time
recovery codes. Forms work without JavaScript. Player/level searches return up to
50 recent matches; administrator and activity lists are bounded to 200 and 100.
Invitation links expire after 24 hours and store only SHA-256 token hashes.
Recipients select their own passwords, and setup tokens cannot be used to log in.
Role delegation cannot grant permissions the acting administrator lacks; built-in
roles are protected. Disabling or reassigning an administrator revokes their
existing tokens. Last-owner protection and self-disable checks prevent lockout.

Browser sessions use HttpOnly SameSite=Strict cookies, an eight-hour absolute
expiry and a 30-minute idle timeout. CSRF tokens are signed and bound to the
session. Pending authenticator secrets are encrypted in a session-bound cookie
that expires after 10 minutes. Password/security changes require the current
password and, when enabled, a current authenticator code; other sessions are
revoked. Recovery codes use PHP-compatible bcrypt hashes, appear once, and are
consumed atomically during browser login. The administrator client API continues
to require TOTP when enabled. Default administrator password hashing uses bcrypt
cost 12. Set `MUCHO_GO_COOKIE_SECURE=1` behind HTTPS and keep
`MUCHO_DOWNLOAD_HMAC_KEY` stable across restarts/replicas for signed browser forms.

This workspace does not yet cover every PHP admin page. Passkeys, news/media
authoring, branding/settings editors, content-pack management, clan administration,
music moderation, database/backup/upgrade tools, analytics and other operational
modules remain to be migrated and accepted. Existing PHP passkeys are retained
in the database but are not accepted by the native login yet. This is a migration
checkpoint, not a complete replacement of the PHP administration panel.
