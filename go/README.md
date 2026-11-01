# MuchoCore Go port — native discovery preview

The goal is a complete standalone Go edition of MuchoCore. This first increment
implements four read-only endpoint families against the existing PHP core's
MySQL/MariaDB schema. It does not run PHP, proxy requests to PHP, migrate data,
or execute schema changes on startup.

The PHP edition remains the complete runtime. This preview cannot yet replace a
GDPS: accounts, levels, scores, comments, social features, Cloud Save, moderation,
admin, plugins, MuchoProtect, installers and background jobs still need ports.
Real game-client acceptance and performance comparisons have not been completed.

## Implemented endpoints

| Endpoint | Behavior |
| --- | --- |
| `getGJSongInfo.php` | Verified songs, canonical song fields and sanitization |
| `getGJGauntlets.php`, `getGJGauntlets19/20/21.php` | Enabled complete gauntlets, stable order, GD hash |
| `getGJMapPacks.php`, `getGJMapPacks19/20/21.php` | Ten packs per page, bounded page, totals and GD hash |
| `getGJDailyLevel.php` | Active daily, weekly or event rotation, correct index offsets |
| `/health`, `checkIfServerOnline.php` | Liveness, plain `1` |
| `/ready` | DB connection probe; `503` and `-1` when unavailable |

The router accepts case differences, `.php`, trailing and repeated slashes,
and repeated `/a/`, `/api/`, `/accounts/`, `/database/` prefixes without POST
redirects. All aliases from the PHP router are carried forward, but an alias
does **not** imply that its handler has been ported. Unsupported routes return
the usual `-1`; there is no fallback runtime.

GD parameters come from POST form fields, not query strings. Scalar URL-encoded
and multipart forms are supported. Invalid encodings and oversized bodies are
rejected with `400` and `413`. The preview caps request bodies at 1 MiB.
Game lookup errors and missing records return plain `-1` with HTTP 200, keeping
database details out of responses and logs. Requests have a three-second DB
deadline; the connection pool is bounded, and SIGINT/SIGTERM drain the server.

## Run

Requires Go 1.26+ and an already initialized MuchoCore database (including song,
content-pack and rotation migrations). The Go module lives in `go/` to avoid
conflicting with Composer's root `vendor/` directory.

```sh
cd go
go mod download
go build -o bin/muchocore ./cmd/muchocore

export MUCHO_GO_DSN='muchocore_reader:YOUR_PASSWORD@tcp(127.0.0.1:3306)/muchocore?charset=utf8mb4'
export MUCHO_GO_ADDR='127.0.0.1:8081'
./bin/muchocore
```

Use a database account with SELECT access to the four discovery tables. The
server reads these two environment variables directly; it does not load `.env`.
`MUCHO_GO_DSN` is required. The default address is `127.0.0.1:8081`. Configure
MySQL TLS through the driver's DSN settings when the database requires it.

```sh
curl -fsS http://127.0.0.1:8081/health
curl -fsS http://127.0.0.1:8081/ready
curl -fsS -X POST http://127.0.0.1:8081/a/database/getGJSongInfo.php -d 'songID=1'
curl -fsS -X POST http://127.0.0.1:8081/database/getGJDailyLevel.php -d 'type=1'
```

## Verify

```sh
cd go
go test -race ./...
go vet ./...
```

The protocol tests consume committed reference vectors produced by the current
PHP router, hash, XOR, song and text encoders. To regenerate from the repo root:

```sh
php go/testdata/protocol-fixtures.php > go/testdata/protocol.json
git diff --exit-code -- go/testdata/protocol.json
```

For integration, create an **empty disposable** database named exactly
`muchocore_go_test` on a local MariaDB test instance, with passwordless root as
used by CI. Never point the fixture generator at an installation's database.
The generator rejects any other database name and any non-empty database.

```sh
mkdir -p work
MUCHO_GO_PHP_TEST_DSN='mysql:host=127.0.0.1;dbname=muchocore_go_test;charset=utf8mb4' \
  php go/testdata/discovery-fixtures.php > work/discovery.json

cd go
MUCHO_GO_TEST_DSN='root@tcp(127.0.0.1:3306)/muchocore_go_test?charset=utf8mb4' \
MUCHO_GO_TEST_FIXTURES="$PWD/../work/discovery.json" \
  go test -race ./...
```

This applies the repository's real PHP migrations and seeds verified/hidden
songs, ordering/filtering cases, multiple pack pages, and expired, disabled,
future and overlapping rotations. It obtains expected responses by invoking
the existing PHP controllers against that database. Go then compares every
response body and HTTP status using the same fixed database clock. CI requires
this integration test; locally it skips when `MUCHO_GO_TEST_DSN` is absent.

## Remaining migration sequence

1. Port client version inference, authentication/password compatibility and
   accounts, with PHP comparison vectors and account abuse protections.
2. Port level listing, transfer, search and score validation; verify real
   clients across the supported GD generations.
3. Port comments, social, messages, rewards, Cloud Save and moderation with
   transaction and concurrency tests.
4. Port admin, jobs, plugins and extension lifecycle, security controls,
   deployment/install/backup workflows and upgrade tooling.
5. Require complete route coverage, data-preserving migration acceptance,
   real-client acceptance, and reproducible latency/memory/load comparisons
   before declaring the Go edition ready to replace PHP.

The Go service can be evaluated alongside PHP on a separate port now. Production
routing and the existing installation are not changed by this preview.
