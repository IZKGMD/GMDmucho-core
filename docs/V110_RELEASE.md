# MuchoCore 1.1.0 release readiness

The `VERSION` file identifies the source tree. It does not mean that a stable
1.1.0 release has been published. The published deployment target is the release
selected at [GitHub Releases](https://github.com/IZKGMD/GMDmucho-core/releases/latest).

## Included in main

- Styled and CSS-free admin interfaces using the same permissions, authentication,
  CSRF validation and actions, with mobile layout and branding fixes.
- Direct clan management, native rating forms and owner-managed web/native News.
- GD 2.209 server routes for social relationships, threads, telemetry and editor
  audio, plus Windows client patching and client-pack source invalidation.
- Existing profile images, clan quests, migration, backup/restore and queue/cache
  reliability work described in the changelog.

Open PRs for later MuchoClient/launcher packages and other proposals are not
automatically included in this source release. Their build results do not certify
the different client source currently on main.

## Current stable-release blocker

### Shared runtime stabilization (2026-10-24)

The shared HTTP regression runner now boots a disposable populated MariaDB
installation through the actual `public/php7.php` entry point. It exercises
password/GJP2 login and account/user ID separation, banned/inactive rejection,
level upload/search/download with exact editor metadata, comments and history,
leaderboard visibility, owner/friend authorization and revocation, read-only
mode, simultaneous login/update/download requests, and synthetic protocol
boundaries from 1.0 through 2.209. PHP process-launch functions are disabled in
the three independent HTTP workers. The business/protocol fixture explicitly
disables MuchoProtect burst limiting; its dedicated security tests remain separate.
It also checks every inventoried route with missing credentials and array-shaped
inputs for public diagnostic leaks, HTTP server errors and core business-data
mutation. This is negative-path coverage, not successful-use certification of
all 160 routes.

Authenticated editor-input regression also rejects array-shaped flags,
non-boolean flag values and integer overflow without changing stored levels.
Valid optional empty flags and leading-zero decimal values retain compatibility.
The Auto flag now survives upload/update storage. All 35 canonical and alias
paths declared by the six P0 contracts are exercised with successful requests;
login/upload aliases additionally reject wrong credentials, and legacy GJP
updates are checked alongside GJP2.

This regression work fixed discarded `settingsString`/`songIDs`/`sfxIDs`,
unstable shared download-deduplication keys, native-PDO friendship queries,
and friend-only level/comment exposure through public reads and history.
Settings loaders explicitly invalidate the small private PHP config in OPcache.
The HTTP runner enables OPcache with timestamp validation disabled and verifies
that switching to read-only mode immediately prevents writes in every worker.

Run against an **isolated local test database only**:

```sh
TEST_DB_HOST=127.0.0.1 TEST_DB_ROOT_PASSWORD=YOUR_TEST_PASSWORD \
  python3 tests/compat/shared-p0-http.py --php "$(command -v php)" \
  --report /tmp/shared-p0-http.json
```

Native integration CI runs it on PHP 7.0 and 8.3 and retains the sanitized
diagnostic report. Its optional timing observations are local HTTP round-trip
measurements on a tiny synthetic fixture. They are not reference-dataset p95
certification, production performance claims or native-client evidence.
The endpoint inventory links this automated evidence while retaining incomplete
certification statuses and the stable-release block.

Run the authoritative checks from the repository root:

```sh
php tests/protocol/endpoint-perfection.php
php tests/release/v110-endpoint-gate.php
php tests/release/v110-endpoint-gate.php --json --report > endpoint-readiness.json
```

The first command validates the inventory and its existing evidence. The second
enforces complete release certification and returns nonzero while it is missing.
`--report` produces a diagnostic report without making the release ready.

At the 2026-10-19 VPS checkpoint, the registry contains **160 routes**, of which
**154 are inventory-only**, with **0 fully release-gated endpoints** and **2,366
blocking findings**. These are certification gaps, not 2,366 demonstrated runtime
defects. Counts change as routes/tests are added; rerun the gate for current data.

To inspect the six critical P0 endpoints without reading thousands of findings:

```sh
python3 tools/release/v110_p0_audit.py
python3 tools/release/v110_p0_audit.py --json > p0-readiness.json
python3 tests/release/v110_p0_audit_test.py
```

For an opt-in, isolated Windows/Android 2.209 capture and per-endpoint server
p95 measurement, see [P0 native capture](V110_P0_NATIVE_CAPTURE.md).
The optional `docker-compose.trace-test.yml` targets **testgdps-app only** and
must never be merged into the production service.

This additional tool is **diagnostic only**. It links the P0 contract dimensions
to the existing sanitized route-observation fixtures and highlights missing
GD 2.209 (`binaryVersion=51`) observations and p95 evidence. The existing 2.2
fixture with `binaryVersion=47` is **not** a 2.209 acceptance test. Neither a
matching request path nor a passing P0 audit test certifies wire compatibility.
The authoritative stable-release gate remains `v110-endpoint-gate.php`.

Required work includes version-aware request/wire and authorization tests,
negative cases, integrity/concurrency coverage for writes, measured p95 budgets,
and genuine client captures for the declared client families. New tests and
captures must be attached to the actual endpoint entries before changing their
statuses. Do not turn synthetic requests into real-client evidence.

The broader [roadmap](V110_ROADMAP.md) also calls for node enrollment/credential
rotation, API token scope enforcement and clean-VPS deployment evidence. Those
tracks must be reconciled with the implemented release scope; a passing general
Validate run does not attest that all roadmap features have shipped.

## Publication

Do not add `.release/v1.1.0.ready` until the required certification passes. Validate
rejects that marker if the endpoint gate still fails. The stable workflow repeats
the gate and pins its source to the successful validated commit.

Stable publication also requires the native integration workflow at the exact
validated main commit. Both PHP 7.0 and 8.3 matrix jobs must succeed in its latest
attempt; a different commit, skipped job, failed run or pull-request run cannot
authorize publication. A release-marker push triggers this integration even when
no runtime file changes. The publisher waits up to 15 minutes for concurrent CI.

Three distributions are built before creating a GitHub release draft:

- `MuchoCore-v1.1.0.zip`: source archive.
- `MuchoCore-v1.1.0-shared-hosting.zip`: PHP 8.2+ package with Composer dependencies.
- `MuchoCore-v1.1.0-PHP7-shared-hosting.zip`: standalone PHP7 shared package.

`checksums.sha256` covers all three archives. Failed builds, checksum mismatches
and upload failures prevent new publication. Only successful uploads of all
archives and checksums allow publication as the latest stable release. Existing
stable tags cannot be moved. Archive repair builds from its requested tag and
omits the native package only when that historical tag predates its builder.

For an existing VPS, the normal stable update remains:

```sh
sudo /opt/mucho-core/update.sh
```

The explicit `MUCHO_UPDATE_CHANNEL=main` override selects development source and
is not equivalent to installing a published stable release. Keep a verified backup
before updating a production installation.
