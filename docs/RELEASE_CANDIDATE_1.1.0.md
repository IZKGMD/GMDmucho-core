# MuchoCore v1.1.0-rc.1 — Release Candidate

> **Prerelease for testing. NOT stable v1.1.0.** The official stable updater
> must continue using the latest published stable release.

This release candidate is built from a pinned source commit on
`release/v1.1.0-rc.1`. Source `VERSION` remains `1.1.0`.

## Included
- Refreshed Admin Panel, optional CSS-free view, mobile and branding fixes.
- News media: JPEG/PNG and YouTube, with draft access checks.
- GD 2.209 experimental social, threads, notifications, moderation and editor-audio backend work.
- Client patcher, backups, migration, worker/cache and shared-hosting updates.
- GDPS website, profile media, clans and quests.

## Known limitations — READ BEFORE INSTALLING
- **Not verified as 100% Geometry Dash 2.209 compatible** on unmodified Windows/Android clients.
- The endpoint release gate is not yet passing: many route contracts lack certified
  negative cases, authentication, real-client fixtures, database/concurrency evidence
  and measured p95. No certification statuses have been altered for this prerelease.
- Some 2.209 responses and moderation workflows are still provisional.
- Individual client/launcher builds in other branches are not part of these source archives.
- This prerelease is intended for isolated test GDPS installations and authorized testers,
  not for unattended upgrades on servers with live player databases.

## Packages
- `MuchoCore-v1.1.0-rc.1.zip`: pinned source snapshot.
- `MuchoCore-v1.1.0-rc.1-shared-hosting.zip`: packaged PHP app with vendor dependencies.
- `v110-certification-report.json`: precise unresolved release-gate findings.
- `p0-readiness.json`: diagnostic-only six-endpoint readiness summary.

Archives are built and ZIP-tested before a draft prerelease is created. If an
upload fails, the draft is not published. The published prerelease is **never**
marked `latest` and cannot replace the stable update channel.

## Test priorities
1. Backup; deploy only to a separate test server/database.
2. Check PHP 8.3+ and MariaDB compatibility; run schema migrations.
3. Smoke-test register/login, upload/download/search, comments, cloud saves,
   reports, admin RBAC and News.
4. Validate 2.209 native thread objects, invites, subscriptions, notifications,
   and all declared 2.209 endpoints with real Windows/Android clients.
5. Record sanitized errors and p95 timing; do not publish credentials, device IDs,
   message contents or private traffic.

See `docs/V110_RELEASE.md`, `docs/V110_P0_NATIVE_CAPTURE.md` and
`docs/RELEASE_NOTES_1.1.0.md` for the full stable-release gates and scope.
