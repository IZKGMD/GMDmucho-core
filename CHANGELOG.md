# Changelog

## v1.0.2 — Release-Based Updates & Admin RBAC

This release moves production updates to published stable GitHub Releases and adds custom administrator roles and permissions.

### Included

- release-only VPS updates: production never deploys ordinary `main` commits;
- `update.sh` remains available for manual deployment and testing;
- systemd-based automatic update checks every 15 minutes by default;
- Admin Panel release detection and stable release update status;
- custom administrator roles with granular permission management;
- role-aware Admin Panel navigation and access control;
- administrator RBAC migration and contract coverage;
- hardened Android client patching and compatibility handling;
- updated deployment and CI checks for release-based update flow.

### Compatibility verification

GD 2.2 verification remains backed by the committed real-client contract fixture:

~~~text
tests/client-fixtures/2.2/endpoints.json
~~~

Legacy 1.0, 1.9, 2.0 and 2.1 protocol behavior remains covered by the repository's compatibility and wire regression tests.

## v1.0.1 — Admin Security & Operations Update

This release updates the Admin Panel authentication and administrator management flow.

### Included

- automatic VPS update checks via systemd timer;
- configurable automatic update interval with a safe disable switch;
- native WebAuthn/FIDO2 passkey sign-in and per-administrator passkey management;
- removal of the legacy Access Key authentication mode;
- separate administrator accounts with owner-controlled built-in roles;
- one-time administrator password setup links so invited admins create their own passwords;
- password setup links expire after 24 hours and are single-use;
- MFA recovery codes for TOTP-enabled administrators;
- live audit feed and hardened administrator security flows;
- updated deployment migrations and CI contracts.

### Compatibility verification

GD 2.2 verification remains backed by the committed real-client contract fixture:

~~~text
tests/client-fixtures/2.2/endpoints.json
~~~

Legacy 1.0, 1.9, 2.0 and 2.1 protocol behavior remains covered by the repository's compatibility and wire regression tests.

## v1.0.0 — Stable Release

MuchoCore v1.0.0 packages the current server core, deployment tooling, client patching tools and compatibility checks into a single stable release.

### Included

- Geometry Dash account, profile, level, social and moderation endpoints;
- version-aware Geometry Dash protocol handling with explicit 2.2 logic;
- GJP2-aware authentication for modern clients;
- cloud save;
- Secret Room / Wraith reward handling;
- player dashboard and administration panel;
- music upload infrastructure;
- Docker Compose deployment with MariaDB and Caddy;
- automated database migrations;
- automated PHP, shell, Python, Docker and Caddy validation;
- Windows client patching tools;
- built-in client tracing and version-aware contract generation.

### Compatibility verification

GD 2.2 verification is backed by a real Geometry Dash 2.2 client contract fixture captured from a live client trace and committed as:

~~~text
tests/client-fixtures/2.2/endpoints.json
~~~

The fixture records Geometry Dash client family 2.2, game version 22, binary version 47, and the observed endpoint contract. The 2.2 release gate validates its provenance and metadata on every CI run.

A synthetic or hand-written fixture does not satisfy the release gate.

### Release verification scope

The stable release gate is backed by the committed real-client GD 2.2 contract fixture. Legacy 1.9, 2.0 and 2.1 protocol behavior remains covered by automated protocol and wire regression tests; their separate real-client release gates activate automatically when corresponding real-client fixtures are committed.
