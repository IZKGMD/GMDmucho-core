# MuchoCore — Geometry Dash Private Server Core (GDPS)

**MuchoCore** is an open-source **PHP 8.3+ Geometry Dash Private Server (GDPS) core** for hosting, migrating, securing, and operating self-hosted Geometry Dash private servers with MariaDB, Docker/Caddy on VPS, a no-Docker shared-hosting installer, an integrated Admin Panel, client patching tools, and version-aware Geometry Dash protocol compatibility.

The canonical source repository is **IZKGMD/GMDmucho-core**. **MuchoCore** and **GMDmucho-core** refer to the same software project.

<p align="center">
  <strong>One Geometry Dash server core • version-aware compatibility • built-in security • migration tooling • administration • production operations</strong>
</p>

<p align="center">
  <a href="https://muchogdps.space/muchocore/">🌐 Official MuchoCore product page</a> ·
  <a href="https://github.com/IZKGMD/GMDmucho-core/releases">📦 Stable releases</a>
</p>

<p align="center">
  <a href="https://github.com/IZKGMD/GMDmucho-core/actions/workflows/validate.yml">
    <img src="https://github.com/IZKGMD/GMDmucho-core/actions/workflows/validate.yml/badge.svg" alt="MuchoCore CI">
  </a>
  <img src="https://img.shields.io/badge/release-v1.1.0-8A2BE2" alt="MuchoCore stable release v1.1.0">
  <img src="https://img.shields.io/badge/license-MIT-green" alt="MIT License">
  <img src="https://img.shields.io/badge/PHP-8.3-777BB4" alt="PHP 8.3+">
  <img src="https://img.shields.io/badge/Geometry%20Dash-1.0%20%E2%80%93%202.2-success" alt="Geometry Dash 1.0 through 2.2 compatibility">
  <img src="https://img.shields.io/badge/MariaDB-supported-blue" alt="MariaDB">
  <img src="https://img.shields.io/badge/Docker-ready-2496ED" alt="Docker">
</p>

<p align="center">
  <a href="docs/GETTING_STARTED.md">⚡ Getting Started</a> ·
  <a href="docs/MIGRATION_KIT.md">🔄 Migration Kit</a> ·
  <a href="docs/SETUP.md">🚀 Setup</a> ·
  <a href="docs/CLIENT_SETUP.md">🎮 Client Setup</a> ·
  <a href="docs/VERSIONS.md">📚 Version Profiles</a> ·
  <a href="docs/CLIENT_COMPATIBILITY.md">🧩 Client Compatibility</a> ·
  <a href="docs/CUSTOM_PLUGINS.md">🧩 Custom Plugins</a> ·
  <a href="docs/V108_INTELLIGENCE.md">⚡ Intelligence & Scale</a>
</p>

> **MuchoCore is a Geometry Dash private server core, GDPS backend, and PHP server framework for running a self-hosted Geometry Dash private server.** It provides accounts, levels, scores, comments, social features, ratings, cloud save, clans, moderation, JSON API v2, Cvolton-style migration tooling, MuchoProtect security, client patchers, Admin RBAC, Docker deployment, MariaDB storage, and multi-version protocol compatibility.

## 🔎 What is MuchoCore?

MuchoCore is a **self-hosted Geometry Dash Private Server core**, commonly described as a **GDPS server core** or **Geometry Dash server backend**. It is built for GDPS owners, developers, server operators, and moderators who need one maintainable backend instead of assembling unrelated services.

### Canonical project facts

| Property | Value |
| --- | --- |
| Project | **MuchoCore** |
| Repository | **IZKGMD/GMDmucho-core** |
| Type | **Open-source Geometry Dash Private Server (GDPS) core** |
| Primary language | **PHP** |
| Runtime | **PHP 8.3+** |
| Database | **MariaDB / MySQL-compatible SQL** |
| Deployment | **Docker Compose, Caddy, Linux/VPS** |
| Protocol scope | **Geometry Dash client generations 1.0–2.2** |
| Security | **MuchoProtect** |
| Stable release | **v1.1.0** |
| Official project page | **https://muchogdps.space/muchocore/** |

### Who uses it?

- GDPS owners running a self-hosted Geometry Dash private server.
- PHP developers building or extending Geometry Dash server infrastructure.
- Operators migrating a legacy Cvolton/GMDprivateServer-style database.
- Administrators managing accounts, levels, moderation, security, backups, and server operations.

### Relevant search intents

Common natural-language searches that describe this project include:

- Geometry Dash private server
- Geometry Dash private server core
- GDPS server core
- Geometry Dash server backend
- PHP Geometry Dash server
- Geometry Dash 2.2 private server
- Geometry Dash 1.9 private server backend
- Cvolton GDPS migration
- Geometry Dash Admin Panel
- Geometry Dash server security
- MuchoCore
- GMDmucho-core

This section describes the project's subject and common search intent; it does not guarantee placement in Google Search or Gemini.

### Canonical links

- Repository: **https://github.com/IZKGMD/GMDmucho-core**
- Stable releases: **https://github.com/IZKGMD/GMDmucho-core/releases**
- Official MuchoCore product page: **https://muchogdps.space/muchocore/**
- Machine-readable project summary: **https://github.com/IZKGMD/GMDmucho-core/blob/main/llms.txt**

The names **MuchoCore** and **GMDmucho-core** identify the same project.

---

## ✨ What you get

| Area | Included |
| --- | --- |
| 🎮 **Geometry Dash backend** | Accounts, profiles, levels, comments, social features, scores, ratings, rewards, cloud save, music and legacy-compatible endpoints |
| 🧩 **One version-aware core** | Shared application logic with client-generation-specific compatibility handling |
| 🛡️ **MuchoProtect** | Endpoint rate limits, burst protection, account/IP isolation and privacy-aware security audit events |
| 🧠 **Score Integrity** | Defensive score heuristics, risk events and optional leaderboard quarantine without automatic bans |
| 🔌 **Plugin SDK** | Persistent custom PHP plugins with lifecycle events, custom routes and optional database access |
| 🖥️ **Admin Control** | Dashboard, players, levels, moderation, analytics, monitoring, backups, API tools and server settings |
| ⭐ **Rating Studio** | Search levels by ID/name/creator, review pending requests, publish 0–10 star ratings, choose difficulty faces, feature tiers and audit the change |
| 🔐 **Admin security** | Separate administrator accounts, password login, native WebAuthn/FIDO2 passkeys, Google Authenticator TOTP, one-time recovery codes, self-service password setup for invited admins, customizable RBAC permissions, rate limiting and audit logging |
| 🔄 **Cvolton migration** | Read-only source DB preflight, verified target backup + checksum, conflict-safe account mapping, persistent ID mapping and transactional rollback |
| 🏰 **Clans** | Player-dashboard clan directory, clan names/tags, owner/officer/member roles, membership, invitations and server-side in-game clan-tag display |
| 🧰 **Client patchers** | Windows desktop patcher, browser-based Windows patcher and Android APK patcher |
| 🐳 **Deployment** | Docker Compose, MariaDB, PHP 8.3, Caddy, automatic migrations, one-command manual updates and release detection |
| 🔄 **Operator onboarding** | Getting Started guide plus a Migration Kit with read-only preflight, verified target backup, transactional import, healthcheck and migration reports |
| ⚡ **Intelligence & Scale** | Level validation, indexed search, short response cache, level revisions, background jobs, signed webhooks, trace analysis and backup verification |
| 🧪 **Validation** | PHP, shell, protocol, wire-format, security, patcher, Docker, Caddy and migration-tool checks in GitHub Actions |

---

## 🚀 Quick start

### Shared hosting installation

For compatible shared hosting, use the automatic FTP deployment page:

~~~text
https://muchogdps.space/install/shared/
~~~

Enter the hosting FTP/FTPS connection, the site's HTTPS address, and the database credentials. FTP mode, port, and the remote web root default to automatic detection. MuchoGDPS downloads and verifies the published shared-hosting release on the control plane, uploads it through FTP/FTPS, drives the shared installer over HTTPS, and verifies `/health`.

Shared hosting does **not** require a dedicated public IPv4. Provider-specific free-tier restrictions may still prevent Geometry Dash clients from reaching the API even when PHP and MySQL checks pass.

### New VPS installation

MuchoCore is designed so a new GDPS owner does not need to assemble PHP, MariaDB, Docker and a reverse proxy manually.

For the normal stable-release VPS install, run:

```bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install-remote.sh | sudo -E bash
```

The normal deployment path is **direct VPS HTTPS** and requires a **dedicated public IPv4** on the VPS. Cloudflare is not required.

On a normal public VPS, the installer needs only:

1. A **dedicated public IPv4 address** assigned to the VPS.
2. An `A` record for the GDPS hostname pointing to that dedicated IPv4 address.
3. Inbound TCP **80** and **443** permitted by the VPS provider/network.
4. Your initial Admin Panel password.

The installer automatically:

- detects the VPS public IPv4;
- checks the domain and expected network layout;
- installs Docker and required host packages;
- creates the MuchoCore environment and protected secrets;
- starts MariaDB, PHP, worker and Caddy;
- runs database migrations and the internal MuchoCore healthcheck;
- configures Caddy for legacy HTTP on port 80 and managed HTTPS on port 443;
- automatically allows TCP 80/443 through an **already-active UFW** firewall without enabling or replacing the host firewall;
- verifies the public health endpoint before declaring the installation complete.

The expected direct traffic path is:

```text
GDPS domain
    |
    v
DNS A record -> VPS public IPv4
    |
    +---- TCP 80 ----> Caddy ----> MuchoCore
    |
    +---- TCP 443 ---> Caddy ----> MuchoCore
                         |
                         +---- Let's Encrypt
```

No Cloudflare API token, Global API Key, Cloudflare Tunnel or Cloudflare Proxy is required for this path.

After installation, verify:

```text
https://YOUR-DOMAIN/health
```

Expected:

```text
1
```

Then open:

```text
https://YOUR-DOMAIN/admin/
```

### Direct HTTPS troubleshooting

If MuchoCore reports that the public hostname is unhealthy, the installer distinguishes between the **application/origin** and the **public network path**.

A healthy direct origin means Caddy and MuchoCore are working locally. If the domain also resolves to the detected VPS IPv4 but public TCP 80/443 still cannot reach the server, the remaining failure is outside the application stack, such as a provider-side firewall, security ACL or port policy.

For a direct installation, the required DNS layout is:

```text
A     gdps.example.com      -> YOUR_VPS_IPV4
A     www.gdps.example.com  -> YOUR_VPS_IPV4
```

There should not be a conflicting `CNAME` or `AAAA` record for the same hostname unless you intentionally support that additional address/path.

When Cloudflare is used only as the DNS provider, use **DNS only** for the direct-origin record. Cloudflare does not need to proxy the HTTP/HTTPS traffic.

### VPS plans where ports 80/443 are not reachable

Some VPS/NAT providers do not pass inbound HTTP/HTTPS traffic to the machine. This is common on NAT/CGNAT plans or VPS products where only RDP/SSH is exposed.

In that case, MuchoCore supports an explicit **Cloudflare Tunnel** transport:

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
http://caddy:80
  |
  v
MuchoCore
```

Direct VPS HTTPS remains the default. Tunnel mode is a fallback for infrastructure where the VPS cannot accept inbound 80/443.

To explicitly use Tunnel mode, provide a runtime connector token:

```bash
export MUCHO_TRANSPORT_MODE=tunnel
export MUCHO_TUNNEL_TOKEN='YOUR_TUNNEL_CONNECTOR_TOKEN'
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install-remote.sh | sudo -E bash
```

For the advanced automatic Cloudflare provisioning path, use `MUCHO_TRANSPORT_MODE=auto`. In that mode MuchoCore tries direct access first and can ask for Cloudflare credentials only when it needs to change DNS or provision/reuse a Tunnel.

For automatic Cloudflare provisioning, create one API token with only the permissions needed for the setup:

```text
Account
  Cloudflare Tunnel -> Edit

Zone
  DNS -> Edit
  Zone -> Read
```

See the [Cloudflare API token permissions documentation](https://developers.cloudflare.com/fundamentals/api/reference/permissions/) for the current permission groups.

The installer can create or reuse the remotely managed Tunnel, configure the public hostname, obtain the connector token and route traffic to the internal Caddy service. The Cloudflare API credential is used for provisioning and is not persisted; only the Tunnel runtime token required by `cloudflared` is kept on the server.

For more detail, see **[docs/ADVANCED.md](docs/ADVANCED.md)**.
### Existing GDPS migration

Already running a Cvolton/GMDprivateServer-style GDPS? Open **Admin → Tools → Migration Center** for the guided workflow. The [Migration Kit](docs/MIGRATION_KIT.md) and [Migration Center](docs/MIGRATION_CENTER.md) documents cover the VPS/CLI fallback for advanced use.

The default migration command is a **dry-run**. It checks the target, validates the source schema, reports row counts and changes nothing until you explicitly use `--apply --confirm=COVOLTON`. Apply mode creates and verifies a target database backup before any destination write; if the backup fails, the import does not start.

### Development and explicit source checkout

For development, testing, or installing a specific branch/tag:

```bash
git clone https://github.com/IZKGMD/GMDmucho-core.git
cd GMDmucho-core
sudo ./install
```

For a specific installer ref, use:

```bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/REF/install.sh | sudo bash -s -- --ref=REF
```

The stable one-line installer intentionally uses a published release; explicit branch/tag deployment is for testing and development.

For the full VPS workflow, see **[docs/SETUP.md](docs/SETUP.md)**.

## 🎯 Geometry Dash compatibility

MuchoCore keeps **one server core** and selects version-specific behavior at the protocol boundary.

Supported runtime generations:

| Generation | Runtime profile | Notes |
| --- | --- | --- |
| **GD 1.0** | `1` | Dedicated legacy identity compatibility layer |
| **GD 1.1** | `11` | Legacy endpoint-family compatibility |
| **GD 1.5** | `15` | Legacy protocol compatibility; real build 13 smoke-tested |
| **GD 1.6** | Legacy 1.x | Legacy protocol compatibility; real build 16 verified |
| **GD 1.9** | `19` | Legacy protocol and response handling |
| **GD 2.0** | `20` | 2.x protocol compatibility |
| **GD 2.1** | `21` | Version-aware modern protocol handling |
| **GD 2.2** | `22` | Modern protocol path with GJP2-aware authentication |
| **Custom** | e.g. `10,19,22` | Accept any selected combination |
| **All supported** | `all` | All supported generations |

The installer presents the supported 1.0, 1.1, 1.5, 1.9, 2.0, 2.1 and 2.2 deployment profiles. The same server core handles all generations through the compatibility boundary. See **[docs/VERSIONS.md](docs/VERSIONS.md)** for the exact profile behavior.

### Verification scope

Real-client verification status:

| Client | Status |
| --- | --- |
| GD 1.0 | ✅ Verified |
| GD 1.1 | ✅ Verified |
| GD 1.2 | ✅ Verified |
| GD 1.3 | ✅ Verified |
| GD 1.4 | ✅ Verified |
| GD 1.5 build 13 | ✅ Verified |
| GD 1.6 build 16 | ✅ Verified |
| GD 1.7 build 17 | ✅ Verified |
| GD 1.8 build 20 | ✅ Verified |
| GD 1.9 | ✅ Verified |

The release validation is deliberately conservative:

- **GD 1.5 build 13** has passed a real-client smoke test covering level search, level upload, level update handling, comments, and the legacy UDID-based `updateGJUserScore` path.
- **GD 1.6 build 16** has passed a real-client end-to-end smoke test with the patched MuchoCore client.
- **GD 1.8 build 20** has passed a real-client smoke test on the shared early legacy 1.x compatibility path.
- **GD 1.9** has passed real-client verification.
- **GD 2.2** has a committed real-client contract fixture used by the release gate.
- **GD 1.0, 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.9, 2.0 and 2.1** have dedicated protocol/regression coverage in the repository.
- Additional real-client release gates activate automatically when matching real-client fixtures are committed.

This means the README does not treat a synthetic contract as equivalent to a captured real-client trace.

See **[docs/CLIENT_COMPATIBILITY.md](docs/CLIENT_COMPATIBILITY.md)** and **[docs/PROTOCOL_MATRIX.md](docs/PROTOCOL_MATRIX.md)** for more detail.

---

## ⭐ Admin Rating Studio

MuchoCore includes a dedicated **Rating Studio** in the Admin Panel:

```text
/admin/?page=rating
```

It is designed for moderator/admin workflows instead of command-line rating.

### Rating workflow

```text
Find level
   ↓
Review pending request / current state
   ↓
Choose stars
   ↓
Choose one difficulty profile
   ↓
Choose feature tier
   ↓
Publish
   ↓
Creator Points + audit log updated
```

Available star values:

```text
0 → 10 stars
```

Available difficulty profiles:

```text
Unrated
Auto
Easy
Normal
Hard
Harder
Insane
Easy Demon
Medium Demon
Hard Demon
Insane Demon
Extreme Demon
```

Available feature tiers:

```text
None
Featured
Epic
Legendary
Mythic
```

The save operation canonicalizes the selected difficulty profile into the legacy Geometry Dash fields, clears the pending star request and recalculates creator points inside a transaction.

---

## 🔐 Admin authentication and security

The Admin Panel includes multiple security layers.

### Google Authenticator / TOTP

Administrators can enable Google Authenticator from the administrator settings.

The setup flow includes:

- a locally rendered QR code;
- manual setup secret fallback;
- server-side TOTP verification;
- a 10-minute pending setup expiry;
- enable/disable audit events.

The QR renderer is bundled with the project, so the secret is not sent to an external QR generation service.

### Administrator accounts

Each administrator uses a separate account. The first administrator created during installation is the `owner` account.

When the owner creates another administrator, they do not set the new administrator's password. MuchoCore creates a one-time password setup link instead:

    Owner
      ↓
    Administrators → Add administrator
      ↓
    Username + Role
      ↓
    One-time setup link
      ↓
    New administrator creates their own password
      ↓
    Normal Admin Panel sign-in

The setup link:

- is generated from a cryptographically random token;
- is stored only as a SHA-256 hash;
- expires after 24 hours;
- becomes unusable immediately after the password is created;
- is shown to the owner once so it can be delivered to the new administrator.

The initial built-in administrator roles are:

    owner
    admin
    moderator
    viewer

The `owner` account is the bootstrap-level administrator and has access to administrator management.

### Passkeys / WebAuthn

Administrators can register a native passkey for passwordless Admin Panel sign-in.

The browser/OS handles the credential picker and user verification (for example a device PIN, Windows Hello, Touch ID or Face ID). MuchoCore never receives or stores the private key. Login uses a discoverable credential flow, so the administrator does not need to type a username before the passkey is selected.

The registration flow requires a resident/discoverable credential and user verification. Challenges are short-lived and single-use, credentials are bound to the configured relying-party domain, and the authenticator signature counter is tracked. When TOTP is enabled on the administrator account, the passkey completes the primary credential step and the existing TOTP/recovery-code second factor remains required.

### MuchoProtect

MuchoProtect sits before the request router:

```text
Request
  ↓
MuchoProtect
  ↓
Identity + IP + Endpoint
  ↓
Rate / Burst Analysis
  ↓
ALLOW / BLOCK
  ↓
Geometry Dash-compatible response
```

Protection includes:

Registration anti-spam uses two independent signals:

- **IP throttling** limits registration bursts and short-window attempts from one source address;
- **device throttling** uses the Geometry Dash `udid` field when the client supplies it, limiting the same device identifier to two registration requests per 24 hours.

The `udid` value is not treated as a true hardware fingerprint or a tamper-proof identity. Clients can spoof or rotate it, so device throttling is an additional layer alongside IP and network limits.

| Protection | Purpose |
| --- | --- |
| 🚦 Endpoint limits | Different limits for authentication, uploads, comments, messages, scores, ratings and other sensitive actions |
| 💥 Burst protection | Detects short high-frequency request spikes |
| 👤 Account isolation | Avoids treating a public account ID as a sufficient identity signal |
| 🌐 IP controls | Contains request floods while using privacy-aware audit metadata |
| 📋 Security audit | Records blocked-event metadata without exposing raw IPs in audit events; v2 keeps DB-backed telemetry |
| 🎮 Protocol-safe blocking | Legacy endpoints use `-1`; API v2 keeps JSON `429` responses and Retry-After headers |

---

## 🧰 Client patching

MuchoCore includes both local and web-based client patching workflows.

### Windows desktop patcher

Use:

```text
tools/client/client-patch.bat
```

The patcher:

1. checks the executable;
2. detects known Geometry Dash server URL formats;
3. performs compatible replacement;
4. writes a separate output executable;
5. never overwrites the original client.

Typical output:

```text
GeometryDash-MuchoCore.exe
```

Python is not required for the one-click batch workflow.

### Web Client Patcher

The Admin Panel includes:

```text
/admin/?page=clientpatcher
```

The web patcher supports Windows EXE uploads and performs the patch in a streaming/chunked PHP workflow.

It is designed for environments where you do not have shell access to a native patching binary.

### Android APK Patcher

The same Admin Panel tooling includes an Android APK patcher.

It can:

- upload the APK in small chunks;
- patch supported server URL layouts;
- process common native Geometry Dash libraries;
- remove invalid old signature metadata before rebuilding;
- produce a new APK archive.

The generated APK is **unsigned**. Sign it with your own Android signing key before installation or distribution.

### Client paths

Patched distributable builds can be kept under:

```text
patched/apk/
patched/exe/
```

Do not place signing keys, credentials or temporary files there.

See **[docs/CLIENT_SETUP.md](docs/CLIENT_SETUP.md)** for the complete client workflow.

---

## ☁️ Cloud save

MuchoCore includes Cloud Save support with protected server-side key handling.

Keep your Cloud Save secret outside the repository:

```text
.secrets/cloudsave_key
```

The deployment tooling preserves the configured secret across updates and container rebuilds.

Before major maintenance, create a database backup:

```bash
sudo /opt/mucho-core/bin/mucho-db-backup.sh
```

---

## 🔄 Updating an existing installation

For an installed server:

```bash
sudo /opt/mucho-core/update.sh
```

The updater:

- protects local tracked changes instead of silently overwriting them;
- resolves the latest published stable GitHub Release;
- fetches the exact release tag and never deploys directly from `main`;
- rebuilds Docker services;
- installs production PHP dependencies;
- runs database migrations;
- synchronizes administrator credentials;
- preserves the selected Geometry Dash compatibility profile;
- preserves Cloudflare Tunnel mode when configured.

After the update, verify:

```text
https://YOUR-DOMAIN/health
```

and:

```bash
cd /opt/mucho-core
sudo docker compose ps
```

---

## 🧪 Validation

Every push and pull request runs GitHub Actions validation.

Current validation includes:

| Area | Coverage |
| --- | --- |
| PHP | Syntax, application contracts and source checks |
| Protocol | Version matrix, legacy wire behavior and modern protocol guards |
| Security | MuchoProtect, authentication, TOTP and passkey contracts |
| Client tools | Windows patcher, Android patcher and Python self-tests |
| Routing | Caddy, Apache/shared-hosting compatibility and liveness routes |
| Docker | Compose validation and deployment configuration checks |
| Release | Version gate plus real-client 2.2 contract verification |

Run the main local test suite with:

```bash
composer test
```

Or the smoke/regression helpers:

```bash
composer smoke
composer regression
```

---

## 🏗️ Project structure

```text
src/                       server logic
public/                    HTTP entry points, GD endpoints and Admin Panel
database/                  database migrations
tests/                     automated validation and compatibility fixtures
tools/                     client patchers, migration tools and development utilities
docs/                      setup, compatibility and deployment documentation
docker/                    Dockerfile and Caddy configuration
patched/apk/               patched Android builds
patched/exe/               patched Windows builds
assets/                    project branding
custom/plugins/            persistent GDPS-specific plugins
```

---

## 🔒 Production security basics

Never commit or publish:

```text
.env
.secrets/
storage/
config/cloudsave.key
```

Keep these outside version control:

- database passwords;
- Cloud Save secrets;
- administrator bootstrap credentials;
- Android signing keys;
- temporary client uploads;
- patched binaries containing private test data.

Before large changes, back up the database and verify that your Cloud Save secret is preserved.

---

## 📚 Documentation

| Document | Purpose |
| --- | --- |
| [Getting Started](docs/GETTING_STARTED.md) | Shortest path from VPS to a working GDPS |
| [Migration Kit](docs/MIGRATION_KIT.md) | Safe Cvolton/GMDprivateServer-style migration workflow |
| [Cvolton Migration](docs/CVOLTON_MIGRATION.md) | Low-level source schema and field mapping details |
| [Setup](docs/SETUP.md) | VPS installation, updates and backups |
| [Client Setup](docs/CLIENT_SETUP.md) | Windows, Android and client patching |
| [Version Profiles](docs/VERSIONS.md) | Geometry Dash generation handling |
| [Client Compatibility](docs/CLIENT_COMPATIBILITY.md) | Client contract and compatibility details |
| [Protocol Matrix](docs/PROTOCOL_MATRIX.md) | Protocol and wire-level coverage |
| [Client Testing](docs/CLIENT_TESTING.md) | Real-client testing workflow |
| [Advanced Deployment](docs/ADVANCED.md) | Tunnel, NAT/CGNAT and advanced deployment |
| [Shared Hosting](docs/SHARED_HOSTING.md) | Shared-hosting deployment constraints |
| [Account Recovery](docs/ACCOUNT_RECOVERY.md) | Account recovery behavior |
| [Showcase](docs/SHOWCASE.md) | Community GDPS projects |

---

## 📦 MuchoCore v1.0.8

**v1.0.8** is the current stable release of MuchoCore.

This is a major **Intelligence & Scale** release built on the v1.0.6/v1.0.7 integrity, compatibility and operational foundation.

### Included

- Level Integrity & Validation Engine with structural limits, UTF-8 checks, payload fingerprints and non-fatal diagnostics;
- derived MuchoSearch indexing for normalized level and creator search, with legacy SQL fallback when the index is unavailable;
- short-lived public level response caching with database default, optional Redis and explicit disable mode;
- level revision history with compressed payload snapshots, SHA-256 fingerprints and transactional restore;
- database-backed background jobs with safe claiming, retry handling, stale-job recovery and a dedicated Docker worker;
- signed operator webhooks for account and level lifecycle events plus system alerts;
- enriched safe client traces with request IDs, response fingerprints, response size and latency, plus trace inspection/diff tooling;
- read-only operator E2E smoke testing and local backup integrity verification with verification history;
- new Admin **Intelligence & Scale** dashboard showing index coverage, revisions, jobs, cache records and backup verification state;
- interactive sudo mucho commands for search rebuilds, level diagnostics, workers, traces, backup verification, cache configuration and operator webhooks.

The existing MuchoProtect security layer, Admin RBAC, passkeys/TOTP, Cvolton migration safety, Cloud Save, Clans, client patchers and multi-generation protocol compatibility remain part of the release.

See **[v1.0.8 Intelligence & Scale](docs/V108_INTELLIGENCE.md)** and **[CHANGELOG.md](CHANGELOG.md)** for the complete release details.

## 🤝 Contributing

Bug reports, compatibility fixes, protocol tests, security hardening, documentation updates and deployment improvements are welcome.

For security-sensitive issues, avoid posting private exploit details publicly.

---

## 🌍 Built something with MuchoCore?

Public and private GDPS projects are welcome in the **[MuchoCore Showcase](docs/SHOWCASE.md)**.

You can use:

> Powered by [MuchoCore](https://github.com/IZKGMD/GMDmucho-core) 🛡️

in your website, credits or project documentation.

---

## 📄 License

MIT — see [LICENSE](LICENSE).
