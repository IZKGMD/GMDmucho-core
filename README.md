# MuchoCore — Geometry Dash Private Server Platform

**MuchoCore** is an open-source **PHP 8.3+ Geometry Dash Private Server (GDPS) core and self-hosted operations platform**.

It combines the Geometry Dash protocol/backend layer with database migrations, security, administration, client patching, backups, observability, plugins, content management, and automated VPS deployment.

> **MuchoCore is not only a GDPS backend. It is the server core, control plane, migration layer, security layer, client factory, and operator toolkit for a self-hosted GDPS.**

<p align="center">
  <strong>One GDPS core • version-aware protocol • security • migration • administration • clients • deployment • operations</strong>
</p>

<p align="center">
  <a href="https://github.com/IZKGMD/GMDmucho-core/releases">📦 Releases</a> ·
  <a href="https://github.com/IZKGMD/GMDmucho-core/actions/workflows/validate.yml">🧪 CI</a> ·
  <a href="docs/GETTING_STARTED.md">⚡ Getting Started</a> ·
  <a href="docs/SETUP.md">🚀 VPS Setup</a> ·
  <a href="docs/MIGRATION_CENTER.md">🔄 Migration Center</a>
</p>

![Release](https://img.shields.io/badge/release-v1.1.0-8A2BE2)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4)
![Database](https://img.shields.io/badge/MariaDB%2FMySQL-supported-blue)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED)
![License](https://img.shields.io/badge/license-MIT-green)

---

## What is MuchoCore?

MuchoCore is designed for operators who want to run a GDPS without stitching together a separate backend, migration script, admin dashboard, client patching tool, backup process, and deployment system.

| Layer | What MuchoCore provides |
| --- | --- |
| 🎮 GDPS core | Accounts, profiles, levels, scores, comments, ratings, social, cloud save, songs, lists, rewards, challenges, creator discovery and legacy-compatible protocol endpoints |
| 🧩 Compatibility | Shared domain logic with protocol/version compatibility at the transport boundary |
| 🛡️ Security | MuchoProtect, rate limits, burst limits, identity/device controls, temporary penalties, security events and hardened request handling |
| 🔐 Admin security | Individual admin accounts, RBAC, TOTP, WebAuthn/FIDO2 passkeys, recovery codes, session limits, audit logging |
| 🖥️ Admin panel | Dashboard, MuchoOps control plane, players, levels, moderation, ratings, migration, intelligence, plugins, content packs, release/client management, backups, monitoring and security views |
| 🔄 Migration | Source schema detection, Cvolton-compatible import adapters, dry-run previews, deterministic mappings, verified backups and transactional import safety |
| 📦 Client tooling | Windows PE patching, Android APK patching, automatic Client Pack generation, SHA-256 manifests and client release metadata |
| 🚀 Deployment | VPS installer, Docker Compose, Caddy, MariaDB, health checks, automatic migrations, deployment jobs and post-deploy client generation |
| ☁️ Network transport | Direct public VPS HTTP/HTTPS by default, with explicit Cloudflare Tunnel support for NAT/CGNAT environments |
| ⚡ Performance | Indexed level search, derived caches, optional Redis, MariaDB-backed jobs, rebuildable derived data, background workers and asynchronous webhooks |
| 🔌 Extensibility | PHP Plugin SDK, event bus, plugin routes, controlled database access, permissions and route-collision protection |
| 🧪 Validation | PHP, shell, protocol, client, security, migration, integration, Docker/Caddy and release-gate tests |

---

# 🧪 Compatibility Lab

MuchoCore includes a read-only Compatibility Lab for inspecting committed real-client fixtures, endpoint coverage and quick 2.2 probes through the existing Admin API Tester.

| Capability | Included |
| --- | --- |
| Fixture discovery | ✅ |
| Endpoint inventory | ✅ |
| Quick 2.2 probes | ✅ |
| Trace diff foundation | ✅ |
| RBAC protection | ✅ |

# ✨ Complete Feature Matrix

## 1. Geometry Dash backend

| Feature | Status | Notes |
| --- | --- | --- |
| Account registration | ✅ | Legacy-compatible account flow |
| Account login | ✅ | Geometry Dash-compatible authentication |
| Account backup | ✅ | Legacy and modern backup flows |
| Account sync | ✅ | Legacy and modern sync flows |
| Lost username / account recovery | ✅ | Tokenized web recovery flow |
| Player profiles | ✅ | Stats, bio, role and progress data |
| Username updates | ✅ | Hardened and rate-limited |
| User search | ✅ | Protocol-compatible search paths |
| Level upload | ✅ | Modern and legacy routing |
| Level download | ✅ | Version-aware encoders |
| Level update | ✅ | Metadata/description updates |
| Level delete | ✅ | Authenticated owner flow |
| Level validation | ✅ | Server-side level safety checks |
| Level revisions | ✅ | Versioned payload snapshots and restore |
| Level transfer | ✅ | Server-side ownership transfer model |
| Level comments | ✅ | Account-linked comments |
| Account comments | ✅ | Player/account comments |
| Likes | ✅ | Level/item interaction support |
| Ratings | ✅ | Stars, demon difficulty and feature tiers |
| Rating requests | ✅ | Pending star suggestions |
| Rewards | ✅ | Reward endpoints and persistence |
| Secret rewards | ✅ | Secret-reward data support |
| Challenges | ✅ | Challenge response support |
| Daily level | ✅ | Daily level compatibility path |
| Gauntlets | ✅ | Legacy-compatible gauntlet data |
| Map Packs | ✅ | Map-pack compatibility |
| Level Lists | ✅ | Server-side level-list management |
| Creator discovery | ✅ | Creator and top-artist surfaces |
| Player scores | ✅ | Classic user score endpoints |
| Level leaderboards | ✅ | Level score responses |
| Platformer scores | ✅ | Dedicated platformer score model |
| User relationships | ✅ | Friends, requests, blocks |
| Private messages | ✅ | Message list/read/send/delete flows |
| Custom content URL | ✅ | Server-controlled content URL |
| Account URL | ✅ | Server-controlled account URL |
| Cloud Save backup | ✅ | Server-side protected storage model |
| Cloud Save sync | ✅ | Restore/sync compatibility |
| Music metadata | ✅ | Song repository/service |
| Music uploads | ✅ | Admin/API upload tooling |
| SFX/content files | ⚙️ | File deployment is separate from DB metadata |
| Clans | ✅ | First-class GDPS-local clan system |
| Content packs | ✅ | Pack creation and management |
| Branding | ✅ | Server branding and social metadata |
| Game roles | ✅ | Player-side role compatibility |
| Admin-managed player roles | ✅ | Controlled by Admin/RBAC permissions |

---

## 2. Geometry Dash protocol compatibility

MuchoCore keeps one application/domain layer and places compatibility behavior at the protocol boundary instead of duplicating the whole backend for every game version.

| Generation / family | Runtime support | Repository coverage |
| --- | --- | --- |
| GD 1.0 | ✅ | Dedicated legacy identity compatibility layer |
| GD 1.1 | ✅ | Legacy endpoint-family compatibility |
| GD 1.5 | ✅ | Legacy protocol compatibility |
| GD 1.9 | ✅ | Legacy 1.9 routing/response handling |
| GD 2.0 | ✅ | Modern 2.0 protocol path |
| GD 2.1 | ✅ | Modern 2.1 protocol path |
| GD 2.2 | ✅ | Modern 2.2 path with GJP2-aware handling |
| Early 1.x clients | 🧪 | Dedicated protocol/regression coverage and selected real-client fixtures |
| Custom profile | ✅ | Example: `11,19,22` |
| All supported | ✅ | Default compatibility profile |

### Compatibility mechanisms

| Mechanism | Description |
| --- | --- |
| Path normalization | Compatibility prefixes, aliases and legacy endpoint forms are normalized centrally |
| Version profiles | Server can allow one generation, several generations or all supported generations |
| Protocol encoders | Dedicated user, relationship, comment, song, level, list and message encoders |
| Legacy text handling | Geometry Dash-specific text normalization/encoding helpers |
| XOR/hash helpers | Protocol-specific hashing and XOR utilities |
| 2.2 authentication | GJP2-aware compatibility path |
| Legacy identities | Dedicated compatibility layer for older account identity models |
| Real traces | Repository fixtures can preserve real client request/response contracts |
| Wire tests | Versioned protocol surface and wire-format tests |
| Fallback behavior | Derived infrastructure failures do not unnecessarily break core protocol paths |

See [docs/VERSIONS.md](docs/VERSIONS.md), [docs/CLIENT_COMPATIBILITY.md](docs/CLIENT_COMPATIBILITY.md) and [docs/PROTOCOL_MATRIX.md](docs/PROTOCOL_MATRIX.md).

---

# 🛰️ MuchoOps Control Plane

MuchoOps is the operator-facing control plane for `/admin/?page=ops`.

| Area | What it shows |
| --- | --- |
| Runtime | Core version, transport mode, domain, database connectivity and latency |
| API | Requests, 5xx errors, rate limits, average response time and busiest routes over 60 minutes |
| Jobs | Queued/running/completed/failed jobs plus recent job state without exposing job payloads |
| Security | Security-event volume and top event types over 24 hours |
| Alerts | Unresolved critical/warning/info alerts |
| Clients | Current Android/Windows release state and maintenance flags |
| Migration | Total/applied/pending migration counts and latest schema versions |
| Search | Level search-index coverage, recent revisions and cache row count |
| Backups | Last verified backup and gzip/SQL verification state |
| Health logs | Last observed health/backup log activity |

The page is protected by `monitoring.view`. Live refresh uses an authenticated, no-store JSON snapshot and keeps the application protocol path independent from the operator dashboard.

---

# 🛡️ 3. MuchoProtect

MuchoProtect is the built-in abuse protection layer for the GDPS transport.

| Protection | Included |
| --- | --- |
| Global per-IP budget | ✅ |
| Endpoint-specific rate limits | ✅ |
| Burst limits | ✅ |
| Network-level budget | ✅ |
| Network penalties | ✅ |
| IP penalties | ✅ |
| Account/identity limits | ✅ |
| Device/UDID-aware registration limits | ✅ |
| Temporary abuse penalties | ✅ |
| Security audit events | ✅ |
| Database-backed limiter | ✅ |
| File-backed limiter | ✅ |
| Client IP normalization | ✅ |
| Trusted proxy / Cloudflare-aware IP handling | ✅ |
| Turnstile integration | ✅ |
| Soft anti-bot protection | ✅ |
| v2 API rate protection | ✅ |
| Safe failure for unavailable v2 security storage | ✅ |

MuchoProtect deliberately does not turn heuristic signals into automatic permanent bans.

---

# 🔐 4. Administrator security

MuchoCore treats the Admin Panel as a separate privileged surface.

| Admin security feature | Included |
| --- | --- |
| Individual administrator accounts | ✅ |
| Owner / Administrator / Moderator / Viewer roles | ✅ |
| Custom roles | ✅ |
| Explicit permissions | ✅ |
| Backend permission enforcement | ✅ |
| Privilege-escalation protection | ✅ |
| TOTP / Google Authenticator | ✅ |
| Native WebAuthn/FIDO2 passkeys | ✅ |
| One-time recovery codes | ✅ |
| Password setup for invited admins | ✅ |
| Password changes | ✅ |
| Session idle timeout | ✅ |
| Maximum session lifetime | ✅ |
| Secure session cookie configuration | ✅ |
| CSRF protection | ✅ |
| Admin audit logging | ✅ |
| Per-action target metadata | ✅ |
| IP logging for admin actions | ✅ |

See [docs/ADMIN_RBAC.md](docs/ADMIN_RBAC.md) and [docs/ACCOUNT_RECOVERY.md](docs/ACCOUNT_RECOVERY.md).

---

# 🖥️ 5. Admin Panel

The Admin Panel is a full operator interface rather than a single database editor.

| Area | Capabilities |
| --- | --- |
| Dashboard | Server overview, operational information and quick actions |
| Players | Search players, inspect accounts, moderation actions, role changes and profile management |
| Player Timeline | Recent level, score, comment, security and operator activity for an individual player |
| Levels | Search, inspect, edit and moderate levels |
| Level Moderation | Moderation-oriented level workflow |
| Rating Studio | Star ratings, demon difficulty, feature tiers and audit trail |
| Migration Center | Source preflight, schema detection, migration preview and safe import |
| MuchoProfiles | Server-side profile/branding-oriented management |
| Plugins | Plugin inventory and administration |
| Intelligence | Indexed levels, jobs, cache, revisions and operational diagnostics |
| Content Packs | Content pack management |
| Release | Server/client release information and release operations |
| Monitoring | Health and operational signals |
| Security Monitoring | MuchoProtect/security-event visibility |
| Database Backup Center | Backup creation, verification and operator workflows |
| Client Patcher | Windows patching tooling |
| Android Patcher | Android APK patching tooling |
| Client Releases | Published Windows/Android client release metadata |
| Client Features | Feature flags/configuration surface |
| Advanced tools | Diagnostic/operator operations |
| API tools | Internal/API management helpers |
| Administrator management | Admin accounts, roles and security |
| Audit | Operator action history |

---

# ⭐ 6. Rating Studio

Rating Studio gives moderators an operator workflow for Geometry Dash level ratings.

| Rating capability | Included |
| --- | --- |
| Search by level ID | ✅ |
| Search by name | ✅ |
| Search by creator | ✅ |
| 0–10 star selection | ✅ |
| Difficulty profile selection | ✅ |
| Demon difficulty selection | ✅ |
| Feature tier selection | ✅ |
| Pending rating request workflow | ✅ |
| Transactional save | ✅ |
| Creator Points recalculation | ✅ |
| Pending request cleanup | ✅ |
| Audit trail | ✅ |

Supported feature tiers include:

`None`, `Featured`, `Epic`, `Legendary`, `Mythic`.

---

# 🏰 7. Clans

Clans are a first-class GDPS-local server feature.

| Clan capability | Included |
| --- | --- |
| Unique clan name | ✅ |
| Short clan tag | ✅ |
| Description | ✅ |
| Open / invite-only mode | ✅ |
| Member limit | ✅ |
| Owner role | ✅ |
| Officer role | ✅ |
| Member role | ✅ |
| Seven-day invitations | ✅ |
| Join / leave | ✅ |
| Invite / accept / decline | ✅ |
| Revoke invitations | ✅ |
| Kick | ✅ |
| Role management | ✅ |
| Ownership transfer | ✅ |
| Disbanding | ✅ |
| Clan bans | ✅ |
| Membership concurrency protection | ✅ |
| Clan audit events | ✅ |
| Player-facing clan directory | ✅ |
| In-game clan tag decoration | ✅ |

The real account username is not rewritten when a clan tag is displayed.

---

# 🔄 8. Migration Center

MuchoCore is designed to migrate existing GDPS databases instead of requiring a clean start.

## Migration safety

| Safety mechanism | Included |
| --- | --- |
| Read-only source inspection | ✅ |
| Automatic source schema detection | ✅ |
| Unknown-source rejection | ✅ |
| Dry-run mode | ✅ |
| Explicit apply confirmation | ✅ |
| Target database backup before writes | ✅ |
| gzip integrity check | ✅ |
| SHA-256 backup verification | ✅ |
| Backup lock / no concurrent backup gate | ✅ |
| Single active migration lock | ✅ |
| Deterministic account mappings | ✅ |
| Deterministic level mappings | ✅ |
| Transactional import | ✅ |
| Password hashing safety | ✅ |
| Post-migration validation/reporting | ✅ |
| Detected-but-not-imported data is reported honestly | ✅ |

## Migration architecture

| Component | Purpose |
| --- | --- |
| Source Detector | Identifies schema families from actual database structure |
| Adapter Registry | Selects an importer for a recognized source family |
| Migration Adapter Interface | Common contract for heterogeneous GDPS schemas |
| Cvolton Migration Adapter | Maps Cvolton/GMDprivateServer-compatible schemas into MuchoCore |
| Import Maps | Persist source-to-target account/level mappings |
| Transaction boundary | Prevents partial application when supported operations fail |
| CLI Migration Center | VPS/automation path |
| Admin Migration Center | Guided operator workflow |

## Data migration status

| Dataset | Current handling |
| --- | --- |
| Accounts | Automatic import |
| Player profiles/progress | Automatic import |
| Levels | Automatic import |
| Classic scores | Automatic import |
| Platformer scores | Automatic import |
| Comments | Detected/reported where dedicated mapping is not yet automatic |
| Friendships / requests / blocks / messages | Detected/reported |
| Lists / Map Packs / Gauntlets / Daily | Detected/reported |
| Legacy moderation data | Detected/reported; not copied into MuchoCore RBAC |
| Song metadata | Detected |
| Music/SFX files | Separate filesystem copy |

---

# 📦 9. Client Factory & automatic patching

MuchoCore can build and publish patched GDPS clients as part of deployment and operator maintenance.

## Supported client tooling

| Client/tool | Included |
| --- | --- |
| Windows PE patcher | ✅ |
| Browser-assisted Windows patching flow | ✅ |
| Android APK patcher | ✅ |
| Automatic post-deploy client generation | ✅ |
| Automatic post-update repatching | ✅ |
| Domain-change repatching | ✅ |
| Client Pack ZIP | ✅ |
| Manifest generation | ✅ |
| SHA-256 checksums | ✅ |
| Client source checksum recording | ✅ |
| Published client release metadata | ✅ |
| Client feature flags/config | ✅ |

## What the patcher understands

| Binary representation | Supported |
| --- | --- |
| Fixed-size HTTP URL layouts | ✅ |
| Fixed-size HTTPS URL layouts | ✅ |
| UTF-16 URL layouts | ✅ |
| Base64 URL layouts | ✅ |
| Null-terminated URL/host fields | ✅ |
| UTF-16 host fields | ✅ |
| Base64 embedded URLs | ✅ |
| Legacy `checkIfServerOnline` typo/path | ✅ |

### Generated artifacts

A successful client build can publish:

`GeometryDash-MuchoGDPS.exe`

`GeometryDash-MuchoGDPS.apk`

`MuchoGDPS-Client-Pack.zip`

`storage/clients/manifest.json`

The manifest records the target GDPS, client versions, patch-engine information, source checksums and generated artifact checksums.

---

# 🚀 10. VPS deployment

**MuchoCore 1.1.0 is VPS-only for production deployment.**

The production architecture uses direct VPS deployment with optional Cloudflare transport modes.

## Automatic deployment flow

| Step | Automatic |
| --- | --- |
| Detect VPS public address | ✅ |
| Validate deployment environment | ✅ |
| Install Docker | ✅ |
| Prepare MariaDB | ✅ |
| Prepare PHP runtime | ✅ |
| Configure Caddy | ✅ |
| Generate protected secrets | ✅ |
| Prepare MuchoCore environment | ✅ |
| Run migrations | ✅ |
| Start services | ✅ |
| Run health checks | ✅ |
| Verify public endpoint | ✅ |
| Build patched clients | ✅ |
| Create Client Pack | ✅ |
| Generate deployment details | ✅ |

### Production traffic

Normal direct VPS mode:

```text
GDPS hostname
     |
     v
DNS A record
     |
     v
VPS public IPv4
     |
  +--+--+
  |     |
 80     443
  |     |
  +-- Caddy --+
        |
        v
    MuchoCore
        |
        +--> PHP
        +--> MariaDB
        +--> Worker
```

### Cloudflare Tunnel fallback

For VPS/NAT/CGNAT environments that cannot accept inbound 80/443:

```text
Internet
   |
   v
Cloudflare
   |
   v
Cloudflare Tunnel
   |
   v
Caddy
   |
   v
MuchoCore
```

Cloudflare Tunnel is an explicit transport mode, not a requirement for normal public VPS deployment.

For automatic Cloudflare provisioning, the required account permission is **Cloudflare Tunnel -> Edit**.

**Direct VPS HTTPS remains the default.** No Cloudflare API token, Global API Key, Cloudflare Tunnel or Cloudflare Proxy is required for this path. A standard installation can use an already-active UFW firewall while MuchoCore configures the required inbound ports.

---

# ☁️ 11. Network and transport options

| Mode | Use case | Status |
| --- | --- | --- |
| Direct VPS HTTP/HTTPS | Standard public VPS | ✅ Default |
| Cloudflare Tunnel | NAT/CGNAT / blocked inbound ports | ✅ Supported |
| Cloudflare API provisioning | Automatic Tunnel/DNS provisioning | ✅ Supported |
| Manual Tunnel connector token | Existing operator-owned Tunnel | ✅ Supported |

---

# 🤖 Automation Center

MuchoCore includes a database-backed scheduler for safe recurring maintenance.

| Capability | Included |
| --- | --- |
| Database-backed schedules | ✅ |
| Enable/disable per task | ✅ |
| Configurable intervals | ✅ |
| Scheduler advisory lock | ✅ |
| Atomic queue + schedule advancement | ✅ |
| Scheduler heartbeat | ✅ |
| Safe registered job types only | ✅ |
| Worker retry handling | ✅ |
| MuchoOps scheduler health | ✅ |
| Admin Automation Center | ✅ |
| Operator CLI | ✅ |

Current built-in schedules:

| Schedule | Default | Job |
| --- | ---: | --- |
| Maintenance cleanup | 6h | `maintenance.cleanup` |
| Security event cleanup | 24h | `security.cleanup` |

The scheduler never evaluates arbitrary PHP, SQL, shell commands or file paths from database rows.

See [docs/AUTOMATION_CENTER.md](docs/AUTOMATION_CENTER.md).

---

# ⚡ 12. Performance and scale features

| Scale feature | Included |
| --- | --- |
| Level search index | ✅ |
| Search index rebuild | ✅ |
| Level list response cache | ✅ |
| Database cache backend | ✅ |
| Optional Redis cache backend | ✅ |
| Cache disable mode | ✅ |
| MariaDB job queue | ✅ |
| Background worker | ✅ |
| Failed job retries | ✅ |
| Stale running job reclamation | ✅ |
| Async signed webhooks | ✅ |
| Level index refresh jobs | ✅ |
| Maintenance cleanup jobs | ✅ |
| Level revisions | ✅ |
| Transactional level restore | ✅ |

---

# 🔗 13. Webhooks and event delivery

MuchoCore can deliver selected lifecycle events to an operator webhook.

| Event | Included |
| --- | --- |
| `account.registered` | ✅ |
| `level.uploaded` | ✅ |
| `level.updated` | ✅ |
| `level.deleted` | ✅ |

Webhooks support configurable URL, optional secret, event filtering, background delivery, HMAC-SHA256 signatures and retryable jobs.

Example:

`X-MuchoCore-Signature: sha256=<HMAC-SHA256>`

---

# 📊 14. Intelligence & observability

| Capability | Included |
| --- | --- |
| Indexed level counts | ✅ |
| Missing-index detection | ✅ |
| Revision counts | ✅ |
| Job queue status | ✅ |
| Cache record visibility | ✅ |
| Backup verification history | ✅ |
| Request correlation IDs | ✅ |
| Request duration | ✅ |
| Response length | ✅ |
| Response SHA-256 | ✅ |
| Protocol trace inspection | ✅ |
| Trace diffing by protocol signature | ✅ |
| E2E health smoke testing | ✅ |
| Structured security events | ✅ |
| Per-route API metrics | ✅ |

Protocol traces intentionally avoid storing full response bodies by default.

---

# 💾 15. Backup and disaster-recovery safety

| Backup capability | Included |
| --- | --- |
| Database dump creation | ✅ |
| gzip compression | ✅ |
| gzip integrity verification | ✅ |
| SHA-256 checksums | ✅ |
| Verification history | ✅ |
| Backup locking | ✅ |
| Pre-migration backup gate | ✅ |
| Backup verification CLI | ✅ |
| Operator backup center | ✅ |
| Explicit restore-oriented tooling | ✅ |

Useful commands:

```bash
sudo mucho backup
sudo mucho backup-verify
sudo mucho doctor
```

A verified backup is required before destructive migration writes.

---

# 🔌 16. Plugin SDK

MuchoCore supports trusted PHP plugins without requiring core protocol controller edits.

| Plugin capability | Included |
| --- | --- |
| Manifest-based registration | ✅ |
| Enable/disable support | ✅ |
| Event subscriptions | ✅ |
| Custom HTTP routes | ✅ |
| MuchoCore PDO access | ✅ |
| Plugin permissions | ✅ |
| Plugin context object | ✅ |
| Plugin lifecycle | ✅ |
| Error isolation | ✅ |
| Route collision detection | ✅ |
| Core route protection | ✅ |
| Sanitized request events | ✅ |
| Sanitized response metadata | ✅ |
| Failure event exception-class reporting | ✅ |

Current event hooks:

`plugin.loaded`, `server.boot`, `request.received`, `request.completed`, `request.failed`.

Plugin permissions are an API contract, not a process sandbox. Only trusted PHP code should be installed.

---

# 🧩 17. JSON API v2

MuchoCore provides a separate JSON API for tools, dashboards and integrations.

| API surface | Included |
| --- | --- |
| Health | ✅ |
| Profile lookup | ✅ |
| Dashboard data | ✅ |
| Branding | ✅ |
| Client configuration | ✅ |
| Heartbeat | ✅ |
| Music listing | ✅ |
| Music upload | ✅ |
| Security events/tools | ✅ |
| Admin client operations | ✅ |
| Request IDs | ✅ |
| Per-route metrics | ✅ |
| Rate limiting | ✅ |
| OpenAPI specification | ✅ |

The client configuration endpoint can expose current client version, minimum supported version, download URL, SHA-256 checksum, release notes, maintenance state and feature flags.

---

# 👥 18. Account management and recovery

| Account feature | Included |
| --- | --- |
| Registration | ✅ |
| Login | ✅ |
| Password hashing | ✅ |
| GJP/GJP2 compatibility | ✅ |
| Account backup | ✅ |
| Account sync | ✅ |
| Username change | ✅ |
| Lost username/recovery workflow | ✅ |
| Recovery token hashing | ✅ |
| Recovery token expiry | ✅ |
| Single-use recovery tokens | ✅ |
| Recovery IP/identity rate limits | ✅ |
| Non-enumerating recovery responses | ✅ |

---

# 💬 19. Social systems

| Social feature | Included |
| --- | --- |
| Friends | ✅ |
| Friend requests | ✅ |
| Blocks | ✅ |
| Remove friend | ✅ |
| Read/delete friend requests | ✅ |
| Private messages | ✅ |
| Account comments | ✅ |
| Level comments | ✅ |
| Likes | ✅ |
| Comment history | ✅ |
| Comment integrity controls | ✅ |
| User search | ✅ |
| User relationships in encoded responses | ✅ |

---

# 🎵 20. Music and content

| Content feature | Included |
| --- | --- |
| Song metadata repository | ✅ |
| Song lookup API | ✅ |
| Admin music management | ✅ |
| Music uploads | ✅ |
| Configurable music moderation requirement | ✅ |
| Content packs | ✅ |
| Content-pack maker workflow | ✅ |
| Large/unbounded Map Pack level handling | ✅ |
| Client-side content configuration | ✅ |

Music binaries may still need operator-managed filesystem deployment depending on the source/provider.

---

# 🧠 21. Score integrity

MuchoCore includes defensive score-integrity tooling.

| Capability | Included |
| --- | --- |
| Score integrity checks | ✅ |
| Suspicion/risk events | ✅ |
| Integrity event storage | ✅ |
| Leaderboard quarantine support | ✅ |
| Auditability | ✅ |
| Non-automatic permanent banning policy | ✅ |

The goal is to surface suspicious behavior for operator review rather than make irreversible decisions from a single heuristic signal.

---

# 🧪 22. Diagnostics and developer tooling

| Tool | Purpose |
| --- | --- |
| `mucho doctor` | Installation/runtime health diagnostics |
| `mucho status` | Production service status |
| `mucho logs` | Service logs |
| `mucho repair` | Restart + migrate + health-check |
| `mucho update` | Stable release update |
| `mucho backup` | Database backup |
| `mucho backup-verify` | Backup integrity verification |
| `mucho client-patch` | Rebuild patched clients |
| `mucho migrate` | Apply pending schema migrations |
| `mucho migration` | Open Migration Center |
| `mucho search` | Search index management |
| `mucho level` | Level revision/validation operations |
| `mucho worker` | Background job worker |
| `mucho trace` | Protocol trace tooling |
| `mucho test` | Production-safe E2E smoke test |
| `mucho test-stack` | Optional integration-test stack |
| `mucho config` | Interactive configuration |
| `mucho install` | Re-run installer |

---

# 🧱 23. Architecture

```text
                 ┌─────────────────────────────┐
                 │      Geometry Dash clients  │
                 └──────────────┬──────────────┘
                                │
                         HTTP / HTTPS
                                │
                                v
                 ┌─────────────────────────────┐
                 │            Caddy            │
                 └──────────────┬──────────────┘
                                │
                                v
                 ┌─────────────────────────────┐
                 │     public/index.php        │
                 │ compatibility routing       │
                 │ MuchoProtect / request gate │
                 └──────────────┬──────────────┘
                                │
                                v
        ┌──────────────────────────────────────────────────┐
        │                    MuchoCore                     │
        │                                                  │
        │ Accounts  Levels  Scores  Social  Clans         │
        │ Comments  Ratings  Music   Cloud Save  Moderation│
        │ Compatibility / Protocol encoders               │
        │ Search / Cache / Jobs / Webhooks                │
        │ Migration adapters / Backup services            │
        │ Plugin SDK / Diagnostics                        │
        └──────────────┬───────────────────────────────────┘
                       │
            ┌──────────┴──────────┐
            │                     │
            v                     v
      ┌───────────┐         ┌────────────┐
      │ MariaDB   │         │  Worker    │
      └───────────┘         └────────────┘
```

---

# 🗄️ 24. Database architecture

MuchoCore uses incremental migrations rather than one permanently frozen SQL dump.

| Database concern | Included |
| --- | --- |
| Incremental schema migrations | ✅ |
| Migration status | ✅ |
| Transaction-aware migration runner | ✅ |
| Migration locking/concurrency protection | ✅ |
| Auth schema | ✅ |
| Profile schema | ✅ |
| Level schema | ✅ |
| Protocol metadata | ✅ |
| Interactions | ✅ |
| Moderation | ✅ |
| Songs | ✅ |
| Cloud Save | ✅ |
| Game roles | ✅ |
| Branding | ✅ |
| Secret rewards | ✅ |
| Score integrity | ✅ |
| Comment integrity | ✅ |
| Admin security | ✅ |
| Admin RBAC | ✅ |
| Passkeys | ✅ |
| Recovery codes | ✅ |
| Plugin administration | ✅ |
| Clans v2 | ✅ |
| Security event storage | ✅ |
| Search/intelligence/scale data | ✅ |
| Session grants | ✅ |
| Content packs | ✅ |

---

# 🛠️ 25. Installation

## Recommended VPS install

| Requirement | Needed |
| --- | --- |
| Linux VPS | ✅ |
| Root or sudo access | ✅ |
| Public GDPS hostname | ✅ |
| DNS A record | ✅ |
| Inbound 80/443 | ✅ for direct mode |
| Docker preinstalled | ❌ |
| PHP preinstalled | ❌ |
| MariaDB preinstalled | ❌ |
| Caddy preinstalled | ❌ |

Start with:

```bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install-remote.sh | sudo -E bash
```

The installer prepares Docker, PHP, MariaDB, Caddy, secrets, migrations, the selected GD compatibility profile and the production services.

See [docs/SETUP.md](docs/SETUP.md).

---

# 🔧 26. Configuration

| Setting | Purpose |
| --- | --- |
| GD compatibility profile | Select supported client generations |
| GDPS domain | Change the production hostname |
| Database password | Rotate database access |
| Admin password | Rotate administrator bootstrap password |
| YouTube import | Enable/disable optional import |
| Music moderation | Require/release music moderation gate |
| Automatic updates | Enable/disable stable-release updater |
| Cache driver | Database / Redis / disabled |
| Cache TTL | Configure level response cache lifetime |
| Webhooks | Configure operator webhook URL/secret/events |
| Transport mode | Direct VPS or Cloudflare Tunnel |

---

# 🔁 27. Automatic updates

MuchoCore can follow published stable releases rather than arbitrary branch commits.

| Update behavior | Included |
| --- | --- |
| Stable release detection | ✅ |
| Ignore drafts | ✅ |
| Ignore prereleases | ✅ |
| Exact release tag deployment | ✅ |
| Existing compatibility profile preserved | ✅ |
| Post-update migrations | ✅ |
| Best-effort client repatching | ✅ |
| Operator update command | ✅ |
| Automatic updater installation | ✅ |

Use:

`sudo mucho update`

---

# 🎮 28. Client distribution workflow

```text
Deploy MuchoCore
      ↓
Run migrations
      ↓
Verify /health
      ↓
Patch Windows client
      ↓
Patch Android client
      ↓
Generate ZIP Client Pack
      ↓
Generate manifest/checksums
      ↓
Publish to storage/clients
      ↓
Show download links in Admin → Clients
```

The deployment flow also generates deployment details for operators.

---

# 🧪 29. Automated validation

| Test category | Included |
| --- | --- |
| PHP syntax/runtime contracts | ✅ |
| Shell contracts | ✅ |
| Protocol surface tests | ✅ |
| Protocol wire tests | ✅ |
| Compatibility profile tests | ✅ |
| Client IP tests | ✅ |
| MuchoProtect tests | ✅ |
| Turnstile tests | ✅ |
| Security storage contracts | ✅ |
| Windows patcher tests | ✅ |
| Android patcher tests | ✅ |
| Client trace tests | ✅ |
| Client compatibility tests | ✅ |
| Migration adapter contracts | ✅ |
| Cvolton migration integration coverage | ✅ |
| Migration concurrency tests | ✅ |
| Admin auth contracts | ✅ |
| Admin RBAC contracts | ✅ |
| Admin passkey contracts | ✅ |
| Admin recovery contracts | ✅ |
| Rating contracts | ✅ |
| Clan contracts | ✅ |
| Social CRUD contracts | ✅ |
| Music upload contracts | ✅ |
| Plugin SDK contracts | ✅ |
| Score integrity contracts | ✅ |
| Intelligence/scale contracts | ✅ |
| Worker entrypoint contracts | ✅ |
| VPS deployment contracts | ✅ |
| Release installer contracts | ✅ |
| Docker/Caddy integration | ✅ |
| Load/integration smoke tests | ✅ |

Real-client verification is treated separately from synthetic protocol tests.

---

# 🔍 30. Protocol trace and compatibility foundations

| Capability | Included |
| --- | --- |
| Client trace recording | ✅ |
| Request ID recording | ✅ |
| Duration recording | ✅ |
| Response length recording | ✅ |
| Response SHA-256 recording | ✅ |
| Trace inspection | ✅ |
| Trace diffing | ✅ |
| Versioned endpoint fixtures | ✅ |
| 2.2 endpoint fixtures | ✅ |
| Protocol surface regression tests | ✅ |
| Protocol wire regression tests | ✅ |

---

# 📁 31. Repository structure

```text
src/                    Core domain and infrastructure
public/                 HTTP entry points and Admin Panel
database/migrations/    Incremental database schema
bin/                    Operator and migration tools
docker/                 Docker/Caddy runtime
tests/                  Automated validation and real-client contracts
tools/                  Developer/client/migration utilities
custom/plugins/         Local plugin root
plugins/                Example plugins
docs/                   Technical/operator documentation
patched/                Client patch output area
assets/                 Project branding
```

---

# 📚 32. Documentation map

| Document | Purpose |
| --- | --- |
| [START_HERE.md](START_HERE.md) | Fastest path for new operators |
| [GETTING_STARTED.md](docs/GETTING_STARTED.md) | Initial setup and concepts |
| [SETUP.md](docs/SETUP.md) | VPS installation |
| [ADVANCED.md](docs/ADVANCED.md) | Tunnel/NAT and advanced scenarios |
| [VERSIONS.md](docs/VERSIONS.md) | Compatibility profiles |
| [CLIENT_SETUP.md](docs/CLIENT_SETUP.md) | Connecting patched clients |
| [CLIENT_COMPATIBILITY.md](docs/CLIENT_COMPATIBILITY.md) | Client compatibility guidance |
| [CLIENT_TESTING.md](docs/CLIENT_TESTING.md) | Real-client validation |
| [CLIENT_AUTO_PATCH.md](docs/CLIENT_AUTO_PATCH.md) | Automatic client patching |
| [MIGRATION_CENTER.md](docs/MIGRATION_CENTER.md) | Admin migration workflow |
| [MIGRATION_KIT.md](docs/MIGRATION_KIT.md) | CLI migration tooling |
| [CVOLTON_MIGRATION.md](docs/CVOLTON_MIGRATION.md) | Cvolton-compatible migration details |
| [PLUGIN_SDK.md](docs/PLUGIN_SDK.md) | Plugin development |
| [CUSTOM_PLUGINS.md](docs/CUSTOM_PLUGINS.md) | Plugin configuration |
| [ADMIN_RBAC.md](docs/ADMIN_RBAC.md) | Admin roles and permissions |
| [ACCOUNT_RECOVERY.md](docs/ACCOUNT_RECOVERY.md) | Player account recovery |
| [CLANS.md](docs/CLANS.md) | Clan system |
| [PROTOCOL_MATRIX.md](docs/PROTOCOL_MATRIX.md) | Protocol coverage |
| [V108_INTELLIGENCE.md](docs/V108_INTELLIGENCE.md) | Intelligence/scale layer |
| [V110_ROADMAP.md](docs/V110_ROADMAP.md) | 1.1.0 roadmap and future work |
| [AUTOMATION_CENTER.md](docs/AUTOMATION_CENTER.md) | Safe recurring task scheduler |

---

# 📤 GDPS Export Pack

MuchoCore can create a transferable ZIP package from **Admin → GDPS Export Pack**.

| Export component | Included |
| --- | --- |
| Verified database backup | ✅ |
| SHA-256 database checksum | ✅ |
| Client manifest | ✅ when published |
| Safe server metadata | ✅ |
| `.env.example` template | ✅ |
| Migration state | ✅ |
| Live `.env` | ❌ intentionally excluded |
| `.secrets/` | ❌ intentionally excluded |
| SSH credentials | ❌ intentionally excluded |

The export format is designed for migration/recovery rather than cloning runtime secrets.

---

# 🏆 33. Why MuchoCore?

MuchoCore's main advantage is **integration**.

A traditional GDPS stack often grows as separate pieces:

```text
GDPS backend
   + SQL scripts
   + custom dashboard
   + patcher
   + migration script
   + backup scripts
   + anti-spam
   + update script
   + deployment notes
```

MuchoCore puts those concerns behind one project model:

```text
                    MUCH0CORE
                        │
      ┌─────────────────┼─────────────────┐
      │                 │                 │
     CORE           CONTROL PLANE       TOOLS
      │                 │                 │
  GDPS API          Admin/RBAC          Patcher
  Protocol          Deployment          Migration
  Accounts          Monitoring          Backups
  Levels            Security            Trace tools
  Social            Updates             Test tools
  Scores            Jobs                Client pack
  Clans             Health              Plugins
```

| Lifecycle stage | MuchoCore feature |
| --- | --- |
| Start a new GDPS | VPS installer |
| Configure it | Operator CLI |
| Connect clients | Client patcher / Client Pack |
| Protect it | MuchoProtect |
| Moderate it | Admin Panel / RBAC |
| Migrate an old GDPS | Migration Center |
| Maintain it | Updates / migrations / jobs |
| Diagnose it | Doctor / health / traces / monitoring |
| Scale it | Index / cache / worker / Redis |
| Extend it | Plugin SDK / API v2 / webhooks |
| Recover it | Backup verification / migration safety |

---

# 📦 34. Release 1.1.0 focus

MuchoCore 1.1.0 is a major platform release focused on making the core easier to operate and harder to break.

| 1.1.0 theme | Included in the architecture |
| --- | --- |
| VPS-first deployment | ✅ |
| Unified deployment jobs | ✅ |
| Automatic client generation | ✅ |
| Client distribution metadata | ✅ |
| Migration adapters | ✅ |
| Safer migration workflow | ✅ |
| Admin RBAC | ✅ |
| Passkeys / TOTP / recovery | ✅ |
| MuchoProtect hardening | ✅ |
| Clans v2 | ✅ |
| Plugin administration | ✅ |
| Intelligence & scale tooling | ✅ |
| Client configuration API | ✅ |
| Expanded diagnostics | ✅ |
| Backup verification | ✅ |
| Release-based updates | ✅ |

The 1.1.0 roadmap continues to evolve around control-plane operations, database adapters, observability, content operations, API access tokens, security hardening and plugin management.

---

# ⚖️ 35. Design principles

| Principle | Meaning |
| --- | --- |
| One core | Domain logic should not be duplicated for every protocol generation |
| Compatibility at the boundary | Legacy wire behavior belongs near the protocol boundary |
| Safe migrations | No silent destructive import |
| Rebuildable derived data | Search/cache/index data can be rebuilt |
| Security by default | Abuse protection is part of request handling |
| Explicit privilege | Admin actions are permission-checked server-side |
| Observable operations | Deployments, jobs and security events should be diagnosable |
| Operator control | Dangerous actions require explicit confirmation |
| Honest status reporting | Detected data is not reported as imported unless it really was |
| Extensible without core forks | Plugins/API/webhooks provide controlled extension points |
| Real-client evidence | Synthetic tests do not replace actual client validation |

---

# 🚀 Quick start

### 1. Point DNS to the VPS

Create an A record:

```text
gdps.example.com → YOUR_VPS_IPV4
```

### 2. Install

```bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install-remote.sh | sudo -E bash
```

### 3. Verify health

```text
https://YOUR-DOMAIN/health
```

Expected:

```text
1
```

### 4. Open Admin Panel

```text
https://YOUR-DOMAIN/admin/
```

### 5. Build/distribute clients

Use **Admin → Clients** or:

```bash
sudo mucho client-patch
```

### 6. Run diagnostics

```bash
sudo mucho doctor
sudo mucho status
```

---

# 🧰 Operator CLI

```text
sudo mucho
sudo mucho status
sudo mucho logs
sudo mucho restart
sudo mucho doctor
sudo mucho repair
sudo mucho update
sudo mucho backup
sudo mucho backup-verify
sudo mucho client-patch
sudo mucho migrate
sudo mucho migration
sudo mucho config
sudo mucho search
sudo mucho level
sudo mucho worker
sudo mucho trace
sudo mucho test
sudo mucho test-stack
```

---

# 📄 License

MuchoCore is licensed under the **MIT License**.

See [LICENSE](LICENSE).

---

# 🔗 Project links

| Resource | Link |
| --- | --- |
| Repository | https://github.com/IZKGMD/GMDmucho-core |
| Releases | https://github.com/IZKGMD/GMDmucho-core/releases |
| CI | https://github.com/IZKGMD/GMDmucho-core/actions |
| Documentation | [docs/](docs/) |
| Compatibility matrix | [docs/PROTOCOL_MATRIX.md](docs/PROTOCOL_MATRIX.md) |
| Migration Center | [docs/MIGRATION_CENTER.md](docs/MIGRATION_CENTER.md) |
| Plugin SDK | [docs/PLUGIN_SDK.md](docs/PLUGIN_SDK.md) |

---

## MuchoCore in one sentence

> **MuchoCore is a version-aware Geometry Dash private server core wrapped in a complete self-hosted platform for deployment, security, migration, administration, client distribution, observability, backups and extensibility.**
