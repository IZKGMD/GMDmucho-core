# 🎮 MuchoCore

<p align="center">
  <strong>Build your own Geometry Dash Private Server.</strong><br>
  Modern infrastructure • 2.2-focused compatibility • built-in protection • easy deployment
</p>

<p align="center">
  <a href="https://github.com/IZKGMD/GMDmucho-core/actions/workflows/validate.yml">
    <img src="https://github.com/IZKGMD/GMDmucho-core/actions/workflows/validate.yml/badge.svg" alt="CI">
  </a>
  <img src="https://img.shields.io/badge/release-v1.0.0%20RC-8A2BE2" alt="Release candidate">
  <img src="https://img.shields.io/badge/license-MIT-green" alt="MIT License">
  <img src="https://img.shields.io/badge/Geometry%20Dash-2.2%20verified-success" alt="Geometry Dash 2.2">
  <img src="https://img.shields.io/badge/MuchoProtect-enabled-success" alt="MuchoProtect">
  <img src="https://img.shields.io/badge/Docker-ready-2496ED" alt="Docker">
</p>

<p align="center">
  <a href="docs/SETUP.md">🚀 Setup</a> ·
  <a href="docs/CLIENT_SETUP.md">🎮 Client Setup</a> ·
  <a href="docs/VERSIONS.md">📚 Version Profiles</a> ·
  <a href="docs/SHOWCASE.md">🌍 Showcase</a>
</p>

> ⚡ **MuchoCore is a modern Geometry Dash Private Server backend built to make GDPS deployment easier, safer, and easier to maintain.**

---

## ✨ Why MuchoCore?

MuchoCore is designed around one simple idea:

**you should spend your time building your GDPS — not fighting the backend.**

| Area | What you get |
| --- | --- |
| 🎮 **Geometry Dash backend** | Accounts, profiles, levels, ratings, comments, social features, scores, and cloud saves |
| 🛡️ **MuchoProtect** | Centralized request protection with per-endpoint limits, burst detection, account/IP isolation, and audit events |
| 🔌 **Version-aware protocol** | Shared server logic with client-generation-specific protocol handling |
| 🧪 **Automated validation** | Protocol, routing, client-contract, security, Docker, and patcher checks |
| 🖥️ **Admin panel** | Web tools for managing players and server data |
| 🎵 **Music infrastructure** | Built-in public music storage and upload flow |
| 🐳 **Docker + Caddy** | Repeatable deployment without manually assembling the stack |
| 🧩 **Maintainable architecture** | PHP services, repositories, controllers, migrations, and compatibility layers |

---

## 🚀 Get a GDPS running in minutes

You do not need to manually install PHP, MariaDB, or Caddy.

```bash
git clone https://github.com/IZKGMD/GMDmucho-core.git
cd GMDmucho-core
sudo ./install
```

Point your domain to the VPS, complete the installer, then verify:

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

For the full walkthrough, see **[VPS Setup](docs/SETUP.md)**.

### The flow

```text
VPS
 ↓
./install
 ↓
MuchoCore + MariaDB + Caddy
 ↓
/health → 1
 ↓
Patch your client
 ↓
🎮 Your GDPS is online
```

---

## 🛡️ MuchoProtect

**Security is part of the core, not an afterthought.**

MuchoProtect sits in front of the request router and evaluates protected actions before they reach the endpoint.

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
Legacy-compatible response
```

| Protection | What it does |
| --- | --- |
| 🚦 **Per-endpoint limits** | Applies different limits to authentication, uploads, comments, messages, scores, ratings, and other sensitive actions |
| 🌐 **IP controls** | Helps contain request flooding while keeping raw IP addresses out of audit events |
| 👤 **Account isolation** | Uses a credential-bound identity fingerprint instead of trusting a public account ID by itself |
| 💥 **Burst protection** | Detects short high-frequency request spikes |
| 📋 **Security audit** | Stores blocked-event metadata using privacy-preserving IP hashes |
| 🎮 **Protocol-safe blocking** | Keeps Geometry Dash failure semantics while preserving normal HTTP transport |

MuchoProtect is enabled by default and can be configured through environment variables.

---

## 🎯 Geometry Dash compatibility

MuchoCore uses **one server core** across supported client generations instead of maintaining separate server copies.

The current release target is **GD 2.2 compatibility**, with version-aware protocol behavior at the server boundary and a real-client contract captured from Geometry Dash 2.2.13.

| Capability | Status |
| --- | :---: |
| Client version detection | ✅ |
| GJP2-aware authentication path | ✅ |
| Modern profile state fields | ✅ |
| Version-aware level / leaderboard handling | ✅ |
| Legacy protocol compatibility layers | ✅ |
| Protocol regression coverage | ✅ |
| Real-client verification | ✅ Verified with GD 2.2.13 contract fixture |

> **2.2 verification:** the release gate is backed by a real Geometry Dash 2.2.13 client contract fixture. Automated CI checks provenance, client family, version metadata, and endpoint coverage on every push.

See **[Version Profiles](docs/VERSIONS.md)** for the supported runtime profiles.

---

## 🧪 Proof, not promises

MuchoCore is continuously validated through GitHub Actions and local test suites.

| Test area | Status |
| --- | :---: |
| PHP source validation | ✅ |
| Router / endpoint compatibility | ✅ |
| Protocol matrix checks | ✅ |
| 2.2 protocol guards | ✅ |
| Protocol hash / wire checks | ✅ |
| Client trace tooling | ✅ |
| Client contract checks | ✅ |
| MuchoProtect security tests | ✅ |
| Windows client patcher checks | ✅ |
| Python patcher self-test | ✅ |
| Docker / Compose validation | ✅ |
| Caddy / routing validation | ✅ |

The goal is simple: **every release should be backed by reproducible checks, not just a README claim.**

---

## 🎮 Connect a Geometry Dash client

For Windows clients, use the included patcher:

```text
tools/client/
```

The patcher creates a separate client file and leaves the original untouched.

For Android 2.2, native libraries commonly contain the server URL inside:

```text
arm64-v8a/libcocos2dcpp.so
armeabi-v7a/libcocos2dcpp.so
```

After modifying an Android package, it must be correctly rebuilt and signed.

Read **[Client Setup](docs/CLIENT_SETUP.md)** for the supported workflows.

> ℹ️ A successful patch operation only proves that known server URL patterns were replaced. Full compatibility still requires testing the actual client build against the server.

### 🌐 Optional: Web Client Patcher

MuchoCore also includes an **optional browser-based Windows client patcher inside the Admin Panel**.

Open:

```text
/admin/?page=clientpatcher
```

Then:

| Step | What to do |
| --- | --- |
| 1️⃣ | Enter your **public GDPS server URL**, for example `https://gdps.example.com` |
| 2️⃣ | Upload the original `GeometryDash.exe` |
| 3️⃣ | Click **Upload & Patch Client** |
| 4️⃣ | Wait for the upload and patch process to finish |
| 5️⃣ | Download `GeometryDash-MuchoCore.exe` |

The web patcher is designed with **shared hosting compatibility** in mind:

- 📦 uploads are split into small chunks;
- 🔄 temporary network failures are retried automatically;
- 🧠 patching is performed as a streaming PHP operation;
- 🐍 no Python is required;
- 🐳 no Docker is required;
- ⚙️ no `exec()`, `shell_exec()`, or external patching binary is required.

Enter the **server root only**. Do not append `/database`; MuchoCore generates the client-compatible URL layout automatically.

> ⚠️ The original executable is never overwritten by the web patcher. Keep your original client as a backup.

### 🤖 Android APK patcher

The same **Admin Panel → Tools → Web Client Patcher** page also includes an **Android APK Patcher**.

It uploads the APK in small chunks, patches supported Geometry Dash server URL layouts inside native libraries and common client files, removes invalid old `META-INF` signature files, and rebuilds a new APK archive.

> ⚠️ Android signing is not included in the shared-hosting PHP patch step. The generated APK is **unsigned** and must be signed with your own Android signing key before installation or distribution.

---

## 🌍 Built something with MuchoCore?

**Show it off.**

Public and private GDPS projects are welcome in the **[MuchoCore Showcase](docs/SHOWCASE.md)**.

You can also use:

> Powered by [MuchoCore](https://github.com/IZKGMD/GMDmucho-core) 🛡️

in your website, credits, README, or admin panel.

The goal is to make MuchoCore more than a repository:

```text
One engine
   ↓
Many GDPS projects
   ↓
More contributors
   ↓
More compatibility testing
   ↓
A stronger GDPS ecosystem
```

---

## 🏗️ Project structure

```text
src/                       ← server logic
public/                    ← HTTP entry points and GD endpoints
database/                  ← database migrations
tests/                     ← automated validation
tools/                     ← client and development tools
docs/                      ← documentation
assets/                    ← project branding
docker/                    ← Docker / Caddy configuration
```

---

## 🔐 Production security basics

Never publish or commit:

```text
.env
config/cloudsave.key
storage/
.secrets/
```

Before making major changes, create a database backup and keep your Cloud Save key safe.

For deployment details, see **[VPS Setup](docs/SETUP.md)** and **[Advanced Deployment](docs/ADVANCED.md)**.

---

## 📦 Release candidate

**v1.0.0** is being prepared around four goals:

1. 🎮 Strong Geometry Dash protocol compatibility
2. 🛡️ Built-in request protection
3. 🧪 Reproducible automated validation
4. 🚀 Simple, repeatable GDPS deployment

MuchoCore is intended to be useful to both first-time GDPS owners and developers who want a maintainable server foundation.

---

## 🤝 Contributing

Bug reports, compatibility fixes, tests, security hardening, documentation improvements, and deployment improvements are welcome.

For security-sensitive reports, avoid posting private exploit details publicly.

---

## 📄 License

MIT — see [LICENSE](LICENSE).
