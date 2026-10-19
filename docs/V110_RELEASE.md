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

Both distributions are built before creating a GitHub release draft. Failed
uploads leave the new release as a draft; only successful uploads of both archives
allow publication as the latest stable release. Existing stable tags cannot be
moved. Archive-repair builds use the requested tag for both distributions.

For an existing VPS, the normal stable update remains:

```sh
sudo /opt/mucho-core/update.sh
```

The explicit `MUCHO_UPDATE_CHANNEL=main` override selects development source and
is not equivalent to installing a published stable release. Keep a verified backup
before updating a production installation.
