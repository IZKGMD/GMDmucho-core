## v1.0.7 — Post-v1.0.6 Audit Hardening

### Migration Safety

- made the interactive Migration Center require a verified target database backup before schema preparation or imported-data writes;
- validate backup existence, minimum size and SHA-256 checksum before the migration can proceed;
- added regression coverage that enforces the backup-before-schema ordering.

### Plugin SDK Privacy

- lifecycle events now receive sanitized request snapshots with a strict non-secret protocol field allow-list;
- plugin lifecycle responses expose transport metadata only, not response bodies;
- plugin failure events expose the exception class name instead of the original exception object or message;
- documented the lifecycle event privacy contract and added regression coverage.

### Runtime Consistency

- v7.1 now prefers the canonical MuchoCore database connection before legacy adapter fallbacks;
- API v2 now uses the same canonical database runtime first, while retaining its existing advanced fallback path;
- removed a duplicate v7.1 canonical connection block introduced during hardening.

### Documentation & Release

- synchronized README and plugin/migration documentation with the actual v1.0.7 behavior;
- retained the v1.0.6 integrity and compatibility hardening underneath these fixes;
- validated the release changes with the full Validate, Windows patcher and MariaDB migration integration jobs.

---

## v1.0.6 — Deep Integrity & Compatibility Hardening

### Score Integrity & Concurrency

- serialized regular and Platformer score writes with database transactions and row locks;
- eliminated the select-then-insert race that could occur when the same player submitted concurrent results;
- kept the existing best-score semantics while making failed score writes roll back cleanly;
- regular score leaderboards now exclude inactive and banned accounts at the database query layer;
- Platformer score leaderboards now exclude inactive and banned accounts consistently.

### Social & Account Data Correctness

- corrected friend-request deletion, friend removal and unblock operations to report whether a real mutation occurred;
- kept friend-request read operations idempotent while still rejecting missing requests;
- fixed numeric player search counts so user-ID searches paginate against the correct total;
- kept creator leaderboards version-aware for both modern 2.x and legacy 1.x clients;
- top and creator leaderboards no longer publish inactive or banned accounts.

### Regression Coverage

- added dedicated v1.0.6 hardening contracts for score concurrency structure, leaderboard filtering, search counting and compatibility behavior;
- retained full validation, migration integration and Windows patcher gates for the release.

---

## v1.0.5 — Platform Hardening & Operator Center

### Security & Authorization

- unified MuchoAdminClient authorization with the canonical AdminRbac permission model;
- preserved legacy rank fields for compatibility while removing numeric-rank enforcement as the API authorization source of truth;
- protected owner-level game-role escalation behind canonical owner permissions;
- added configurable trusted proxy CIDRs for safer forwarded client IP handling.

### Score Integrity

- added a persisted quarantined score state behind the existing optional anti-cheat quarantine flag;
- suspicious regular and Platformer scores are stored as quarantined when the feature is enabled;
- leaderboards exclude suspicious and quarantined integrity records while quarantine is active;
- kept the soft-by-default behavior with no automatic account bans.

### Shared MuchoProtect Storage

- added backend interfaces plus MariaDB-backed rate-limit and penalty storage;
- kept file-backed storage as the default for simple single-instance installations;
- added migration and regression coverage for shared security state.

### Migration & Operator Center

- added the guided Migration Center with schema detection, read-only source access, dry-run preview and explicit MIGRATE confirmation;
- added the interactive VPS Control Center via `sudo mucho`;
- added database password rotation via `sudo muchodb-password`;
- added installer and updater integration for the operator tools.

### Release & Update Reliability

- made GitHub's explicit latest stable release the authoritative stable channel;
- replaced the semver-only downgrade guard with a Git ancestry check, so release numbering can change without allowing unrelated source rebases;
- refreshed stable configuration and release documentation for v1.0.5.

---

# Changelog

## v1.0.41 — Migration Safety & Backup Hardening

### Migration Safety

- made a verified MuchoCore target database backup mandatory before migration apply;
- verify the backup file, gzip integrity and SHA-256 checksum before destination writes begin;
- block migration completely when the target backup fails or cannot be verified;
- keep source database access read-only during import;
- harden source-schema preflight so incompatible required columns fail before destination changes;
- refuse implicit account merges when a source account conflicts with an existing target username/email;
- keep destination changes inside a transaction so failed imports roll back cleanly;
- preserve idempotent re-runs through persistent source-to-target ID mapping;
- added an end-to-end MariaDB migration test covering successful import, backup failure, idempotent re-run, account conflicts, rollback and incompatible source schema handling;

### Backup & Operator Reliability

- fixed backup credential loading for installations that store DB credentials in MuchoCore runtime environment files;
- made the backup lock path runtime-configurable and safe for non-root integration environments;
- kept backup options compatible with the normal application database privileges;
- clarified that a public GDPS hostname such as `ps.fhgdps.com` is not automatically a database endpoint; operators must provide the actual source MariaDB/MySQL connection details;

---
## v1.0.4 — MuchoCore Discovery & Tenant Isolation

### Product Page

- added the canonical MuchoCore product page at `/muchocore/`;
- added machine-readable SoftwareApplication and FAQ structured data;
- added explicit documentation for compatibility, architecture, Cvolton migration, plugins, security, deployment and administration;
- added a responsive layout and direct links to source, releases and setup documentation.

### Search & AI Discovery

- added `robots.txt` with crawler directives for OAI-SearchBot, GPTBot, Googlebot and Google-Extended;
- added `sitemap.xml` for the canonical MuchoCore web surface;
- added `llms.txt` with canonical sources and project facts;
- linked the canonical product page from the public project homepage and README.

### Tenant Isolation

- added `MUCHOCORE_SITE_HOST` as the explicit host allow-list for the public MuchoCore discovery surface;
- product/discovery paths return 404 on non-canonical GDPS hosts;
- fresh installations default `MUCHOCORE_SITE_HOST` to `disabled.invalid`, so tenant owners do not receive the MuchoCore product page automatically;
- removed the MuchoCore product-page link from normal GDPS tenant navigation;
- added the operator-friendly Migration Kit with read-only preflight, target backup, transactional apply, post-migration healthcheck and migration reports;
- added `docs/GETTING_STARTED.md` as the shortest installation and migration path;
- added `docs/MIGRATION_KIT.md` with migration commands, safety behavior, password handling and current scope;
- added automated Migration Kit contract checks to release validation.

---

## Unreleased

No pending release notes.

## v1.0.42 — MuchoProtect Hardening & Compatibility Reliability

### MuchoProtect

- added pre-auth identity protection for usernames and email addresses so login and registration abuse cannot be bypassed by simple IP rotation;
- added stable device/UDID identity throttling for legacy client flows;
- added explicit rate and burst policies for the complete Clan API surface;
- added secondary IPv4 /24 and IPv6 /64 endpoint budgets to contain distributed IP rotation without treating a shared network like one client;
- reused exponential temporary penalties for repeated network-level violations;
- unified API v2 request throttling with the central MuchoProtect engine;
- preserved the API v2 JSON 429 contract and the existing music-upload 5-per-15-minute account limit;
- added bounded stale-state cleanup for rate-limit and penalty storage, with lightweight automatic cleanup and explicit maintenance support.

### Clans & Compatibility

- expanded Clan System v2 with owner-only settings, ownership transfer and disbanding;
- added invitation revocation and persistent clan bans with transactional membership cleanup;
- hardened open-clan joins and invitation acceptance with row locking and server-side capacity checks;
- added clan management audit events and contract coverage;
- added first-class GD 1.1 compatibility profile and installer support;
- added first-class GD 1.5 compatibility profile with real build 13 verification;
- verified GD 1.6 build 16 on the shared early 1.x compatibility path;
- preserved legacy UDID-based score compatibility for early clients.

### Operator & Quality Fixes

- removed the obsolete v1.0.2 release marker from the tracked tree;
- fixed the installer summary and password prompt to display the configured administrator username instead of a hard-coded admin;
- updated account-recovery email copy to the project's English-language standard;
- refreshed release documentation and security regression coverage for the new protection layers.

---

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
- fixed updater Compose argument expansion for normal and Cloudflare Tunnel deployments;
- fixed fresh installations so configured Caddy extra hostnames are included in the generated Caddy address;
- fixed the automatic updater systemd unit to write the real installation path instead of a literal shell variable.

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
