# MuchoCore 1.1.0 — Major Release Roadmap

MuchoCore 1.1.0 is planned as a major operator and platform release.

The goal is to move MuchoCore from a strong GDPS core with deployment tooling into a complete self-hosted control platform: deploy nodes, manage them, keep them healthy, migrate different databases, operate backups, and expose a secure API without giving the control plane unnecessary long-term root access.

## Release themes

### 1. Control Plane & Node Agent
- Mucho Agent for customer VPS nodes.
- Short-lived enrollment tokens.
- Node identity with rotating credentials.
- Heartbeat and health telemetry.
- Node status: online, degraded, offline, maintenance.
- Remote actions: restart, update, backup, healthcheck, logs.
- Job history with progress and exit status.
- No permanent root password stored by the control plane.

### 2. Web Deployment 2.0
- Installation method selector: automatic or manual.
- Guided DNS and port preflight.
- VPS capability checks before deployment.
- Deployment profiles for standard, legacy and advanced setups.
- Better live logs with stages and percentage progress.
- Safe retry/resume for failed deployments.
- Deployment history in the control plane.
- Credential lifetime limited to the deployment session.

### 3. Admin Control Center
- Unified node dashboard.
- CPU, memory, disk and service health telemetry.
- Recent operations and failures.
- Maintenance mode with operator notes.
- Scheduled maintenance windows.
- Server announcement/banner management.
- Global operational notifications.

### 4. Backup & Disaster Recovery
- Scheduled database backups.
- Backup retention policies.
- Backup verification history.
- Restore workflow with explicit confirmation.
- Download/export backup metadata.
- Pre-update automatic backup.
- Disaster-recovery checklist and verification command.

### 5. Database Adapter & Migration System
- Formal adapter interface for heterogeneous GDPS schemas.
- Source schema detection.
- Adapter capability report.
- Field mapping preview.
- Per-table migration status.
- Dry-run diff.
- Conflict strategy selection.
- Resumable migration jobs.
- Post-migration validation report.
- First-class support for Cvolton/GMDprivateServer variants without assuming one schema.

### 6. API v3 & Access Tokens
- Scoped personal/operator API tokens.
- Token hashing instead of plaintext storage.
- Expiration and revocation.
- Per-token rate limits.
- Audit events for token creation/use/revocation.
- Safer automation endpoints.
- OpenAPI documentation update.

### 7. Security 2.0
- Harden account session grants against account-ID/IP-only reuse.
- Device/session identifiers where protocol allows.
- Explicit legacy compatibility fallback only where required.
- Security events for suspicious session reuse.
- Better admin session management.
- Active-session revocation.
- Login history and security notices.

### 8. GDPS Content Operations
- Better level moderation queue.
- Bulk level actions with permission checks.
- Featured/rated/unlisted state tooling.
- Collection import/export.
- Content pack duplication.
- Creator profile management improvements.
- Safer bulk operations with preview + confirmation.

### 9. Observability
- Request correlation IDs across app, worker and node operations.
- Operator event timeline.
- Structured error categories.
- Slow request detection.
- Node/service health snapshots.
- Exportable diagnostic bundles for support.

### 10. Plugin Platform 2.0
- Plugin health status.
- Plugin enable/disable state.
- Plugin version metadata.
- Dependency checks.
- Plugin event diagnostics.
- Safer route collision reporting.
- Admin UI for plugin configuration.

### 11. UX / Documentation
- Unified MuchoGDPS installation flow.
- Better mobile Admin Panel navigation.
- Installation troubleshooting wizard.
- Updated architecture documentation and diagrams.
- Complete 1.1.0 migration notes.
- Versioned docs and release checklist.

### 12. Protocol Perfection & Endpoint Certification
- Machine-readable endpoint contract registry.
- Version-aware request and wire contracts for every supported Geometry Dash endpoint.
- Explicit authentication policy and legacy fallback boundary per endpoint.
- Golden and negative fixtures for protocol-critical flows.
- Database-integrity and concurrency coverage for write endpoints.
- p95 performance budgets for hot endpoints.
- Real-client evidence linked to endpoint certification.
- CI rejects release-gated endpoints with any pending quality dimension.
- Endpoint certification standard documented in `docs/ENDPOINT_PERFECTION.md`.

## Release gates

1. Full PHP, shell and protocol validation.
2. Migration integration and rollback coverage.
3. Real-client compatibility gates remain green.
4. Deployment installer contract passes from a clean VPS.
5. Node enrollment and credential rotation are covered by automated tests.
6. Backup + restore verification passes.
7. Security session isolation regression suite passes.
8. API token scope enforcement passes.
9. Documentation and release metadata match VERSION.
10. Every supported Geometry Dash endpoint is represented in the Endpoint Perfection registry; release-gated endpoints have no pending quality dimension.
11. Only after all gates pass: create the 1.1.0 release marker and publish the stable release.

## Intentionally deferred

- Cloud-hosted multi-tenant hosting owned by MuchoGDPS.
- Permanent storage of customer root passwords.
- Automatic destructive migrations without an explicit operator confirmation.
- Automatic account bans based only on heuristic security signals.
