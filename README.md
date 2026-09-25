# 🎮 MuchoCore

<p align="center">
  <strong>A modern Geometry Dash Private Server core.</strong><br>
  One backend • version-aware protocol compatibility • built-in protection • practical deployment tools
</p>

<p align="center">
  <a href="https://github.com/IZKGMD/GMDmucho-core/actions/workflows/validate.yml">
    <img src="https://github.com/IZKGMD/GMDmucho-core/actions/workflows/validate.yml/badge.svg" alt="CI">
  </a>
  <img src="https://img.shields.io/badge/release-v1.0.0-8A2BE2" alt="Stable release">
  <img src="https://img.shields.io/badge/license-MIT-green" alt="MIT License">
  <img src="https://img.shields.io/badge/PHP-8.3-777BB4" alt="PHP 8.3">
  <img src="https://img.shields.io/badge/Geometry%20Dash-1.0%20%E2%80%93%202.2-success" alt="Geometry Dash 1.0 through 2.2">
  <img src="https://img.shields.io/badge/MuchoProtect-enabled-success" alt="MuchoProtect">
  <img src="https://img.shields.io/badge/Docker-ready-2496ED" alt="Docker">
</p>

<p align="center">
  <a href="docs/SETUP.md">🚀 Setup</a> ·
  <a href="docs/CLIENT_SETUP.md">🎮 Client Setup</a> ·
  <a href="docs/VERSIONS.md">📚 Version Profiles</a> ·
  <a href="docs/CLIENT_COMPATIBILITY.md">🧩 Compatibility</a> ·
  <a href="docs/SHOWCASE.md">🌍 Showcase</a>
</p>

> ⚡ **MuchoCore is a maintainable Geometry Dash Private Server foundation for owners who want one server core, version-aware compatibility, an integrated admin panel, client patching tools, and a security layer that can be validated in CI.**

---

## ✨ What you get

| Area | Included |
| --- | --- |
| 🎮 **Geometry Dash backend** | Accounts, profiles, levels, comments, social features, scores, ratings, rewards, cloud save, music and legacy-compatible endpoints |
| 🧩 **One version-aware core** | Shared application logic with client-generation-specific compatibility handling |
| 🛡️ **MuchoProtect** | Endpoint rate limits, burst protection, account/IP isolation and privacy-aware security audit events |
| 🧠 **Score Integrity** | Defensive score heuristics, risk events and optional leaderboard quarantine without automatic bans |
| 🔌 **Plugin SDK** | Permissioned PHP plugins with lifecycle events, custom routes and optional database access |
| 🖥️ **Admin Control** | Dashboard, players, levels, moderation, analytics, monitoring, backups, API tools and server settings |
| ⭐ **Rating Studio** | Search levels by ID/name/creator, review pending requests, publish 0–10 star ratings, choose difficulty faces, feature tiers and audit the change |
| 🔐 **Admin security** | Password login, Google Authenticator TOTP, one-time setup flow, one-time recovery codes, access keys, rate limiting and audit logging |
| 🔄 **Cvolton migration** | Read-only source DB preflight, account/profile/level/score migration, persistent ID mapping and transactional apply |
| 🧰 **Client patchers** | Windows desktop patcher, browser-based Windows patcher and Android APK patcher |
| 🐳 **Deployment** | Docker Compose, MariaDB, PHP 8.3, Caddy, automatic migrations and update tooling |
| 🧪 **Validation** | PHP, shell, protocol, wire-format, security, patcher, Docker and Caddy checks in GitHub Actions |

---

## 🚀 Quick start

MuchoCore is designed so you do not have to assemble PHP, MariaDB and Caddy manually.

```bash
git clone https://github.com/IZKGMD/GMDmucho-core.git
cd GMDmucho-core
sudo ./install
```

The installer configures the database, PHP runtime, Caddy, Cloud Save secret, administrator account and the selected Geometry Dash compatibility profile.

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

For the full VPS workflow, see **[docs/SETUP.md](docs/SETUP.md)**.

### Deployment flow

```text
VPS
  ↓
sudo ./install
  ↓
MuchoCore + MariaDB + Caddy
  ↓
/health → 1
  ↓
Patch the client
  ↓
🎮 GDPS online
```

---

## 🎯 Geometry Dash compatibility

MuchoCore keeps **one server core** and selects version-specific behavior at the protocol boundary.

Supported runtime generations:

| Generation | Runtime profile | Notes |
| --- | --- | --- |
| **GD 1.0** | `10` | Dedicated legacy identity compatibility layer |
| **GD 1.9** | `19` | Legacy protocol and response handling |
| **GD 2.0** | `20` | 2.x protocol compatibility |
| **GD 2.1** | `21` | Version-aware modern protocol handling |
| **GD 2.2** | `22` | Modern protocol path with GJP2-aware authentication |
| **Custom** | e.g. `10,19,22` | Accept any selected combination |
| **All supported** | `all` | All supported generations |

The installer currently presents the standard 1.9–2.2 deployment profiles, while the core also contains the GD 1.0 compatibility layer. See **[docs/VERSIONS.md](docs/VERSIONS.md)** for the exact profile behavior.

### Verification scope

The release validation is deliberately conservative:

- **GD 2.2** has a committed real-client contract fixture used by the release gate.
- **GD 1.0, 1.9, 2.0 and 2.1** have dedicated protocol/regression coverage in the repository.
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

### Access Key

Administrators can generate a dedicated access key for fast sign-in.

Important properties:

- the raw key is shown only when generated;
- only a one-way password hash is stored;
- the key can be used without entering the username;
- the key can be revoked;
- access-key generation, login and revocation are audited;
- **Access Key does not bypass 2FA** — when TOTP is enabled, the second factor is still required.

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

| Protection | Purpose |
| --- | --- |
| 🚦 Endpoint limits | Different limits for authentication, uploads, comments, messages, scores, ratings and other sensitive actions |
| 💥 Burst protection | Detects short high-frequency request spikes |
| 👤 Account isolation | Avoids treating a public account ID as a sufficient identity signal |
| 🌐 IP controls | Contains request floods while using privacy-aware audit metadata |
| 📋 Security audit | Records blocked-event metadata without exposing raw IPs in audit events |
| 🎮 Protocol-safe blocking | Uses Geometry Dash failure semantics such as `-1` instead of requiring HTTP errors |

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
- fetches the current `main` source;
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
| Security | MuchoProtect, authentication, TOTP and access-key contracts |
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
tools/                     client patchers and development utilities
docs/                      setup, compatibility and deployment documentation
docker/                    Dockerfile and Caddy configuration
patched/apk/               patched Android builds
patched/exe/               patched Windows builds
assets/                    project branding
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

## 📦 MuchoCore v1.0.0

**v1.0.0** is the stable release line for the current MuchoCore architecture.

It brings together:

- one version-aware GDPS backend;
- MuchoProtect request protection;
- the Admin Control Panel;
- Rating Studio moderation tools;
- Google Authenticator 2FA and Access Keys;
- Cloud Save;
- Windows and Android client patchers;
- Docker + Caddy deployment;
- automatic migrations;
- reproducible CI validation;
- client trace and contract tooling.

See **[CHANGELOG.md](CHANGELOG.md)** and the **[v1.0.0 release](https://github.com/IZKGMD/GMDmucho-core/releases/tag/v1.0.0)** for release-specific verification details.

---

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
