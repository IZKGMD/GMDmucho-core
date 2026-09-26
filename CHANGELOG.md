# Changelog

## Unreleased

## v1.0.3 — Persistent Plugins & Operations

MuchoCore v1.0.3 expands the custom extension layer and improves the operator experience around long-lived GDPS installations.

### Custom Plugins

- added a persistent `custom/plugins/` extension layer for GDPS-specific functionality;
- custom plugins are kept outside the tracked core source tree and are ignored by Git;
- core updates preserve installed custom plugins instead of treating them as core changes;
- documented plugin manifests, permissions, lifecycle events and installation workflow;
- added regression coverage for loading a custom plugin from the persistent plugin directory.

- added Plugin SDK API compatibility metadata;
- added optional minimum and maximum MuchoCore version guards for custom plugins;
- incompatible or disabled plugins are skipped instead of being executed;
- added read-only plugin diagnostics without executing `plugin.php`;
- added an Admin Panel **Custom Plugins** page with plugin status, versions, permissions and compatibility details;
- added the `plugins.view` RBAC permission;
- preserved compatibility with existing manifests that omit the new optional fields.

### Deployment & Reliability

- improved Docker PHP runtime setup so OPcache uses the packaged extension instead of recompiling it during every image build;
- fixed production update rollback handling so failed container builds and interrupted deployments do not leave the source tree on the new release;
- added runtime verification for both the primary application and the test GDPS tenant before an update is finalized;
- fixed test tenant database runtime configuration so `testgdps-app` connects to `testgdps-db` instead of the primary `db` service;
- added same-version release tag SHA verification so republished stable releases are detected and can be installed safely;
- fixed stable release changelog extraction so published release descriptions include the correct version section.

### Validation & Reliability

- expanded plugin regression coverage for compatible and incompatible manifests;
- added release CI gates for the new plugin diagnostics module and migration;
- kept custom plugin files outside the tracked core source so stable core updates continue to preserve GDPS-specific extensions.

## v1.0.2 — Release-Based Updates & Admin RBAC

MuchoCore v1.0.2 introduces a release-driven production update pipeline, customizable administrator roles and permissions, and hardened Android client patching.

### Release-Based Updates

- production auto-updates now follow published stable GitHub Releases instead of the `main` branch;
- draft releases, prereleases and ordinary `main` commits are ignored by the production updater;
- `update.sh` remains the manual deployment engine and single source of update logic;
- added `auto-update.sh` as the systemd-compatible automatic update wrapper;
- added a systemd timer with a 15-minute default update interval;
- updates are fetched from the exact immutable release tag;
- added protection against unintended version downgrades;
- added protection against deploying over tracked local Git changes;
- `install.sh` now installs the latest published stable release;
- automatic update installation is configurable through `MUCHO_AUTO_UPDATE` and `MUCHO_AUTO_UPDATE_INTERVAL`.

### Admin Panel

- added the **Core Updates** page for release status and update information;
- added stable-release detection to the Admin Panel dashboard;
- added role-aware navigation and access checks for administration features;
- improved visibility of the currently installed and available core versions.

### Admin RBAC

- added customizable administrator roles;
- administrators with the required permission can create, edit and delete custom roles;
- added granular permission assignment for custom roles;
- added role-based access control for system and update management;
- preserved built-in administrator roles and their existing permission model;
- added RBAC database migration and regression coverage.

### Android Client Patching

- hardened APK patching and signing workflows;
- fixed APK alignment handling;
- switched generated Android signing keys to DER-encoded PKCS#8 format for reliable `apksigner` compatibility;
- added migration support for legacy signing key formats;
- added validation for generated signing keys;
- improved APK signature replacement and verification;
- added regression coverage for fresh signing and legacy signer migration;
- improved Android test fixtures to use a valid binary Android manifest.

### Deployment & CI

- improved Docker Android build-tool support;
- hardened production Android signer initialization and permissions;
- improved portability of the systemd auto-update installer by using the configured MuchoCore root;
- expanded CI coverage for release-based updates, Admin RBAC and Android client patching.

### Compatibility

- existing manual `update.sh` deployments remain supported;
- existing installations can migrate to the release-based automatic update flow;
- legacy Android signing keys are migrated automatically when they match a supported legacy format;
- Geometry Dash compatibility and protocol regression coverage remains active through the repository test suite.

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
