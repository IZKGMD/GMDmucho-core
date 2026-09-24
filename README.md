# 🎮 MuchoCore

<p align="center">
  <strong>A modern backend for Geometry Dash Private Servers (GDPS)</strong><br>
  Built for protocol compatibility, maintainability, and real-world server protection.
</p>

<p align="center">
  <a href="https://github.com/IZKGMD/GMDmucho-core/actions/workflows/validate.yml">
    <img src="https://github.com/IZKGMD/GMDmucho-core/actions/workflows/validate.yml/badge.svg" alt="CI">
  </a>
  <img src="https://img.shields.io/badge/release-v1.0.0%20RC-8A2BE2" alt="Release candidate">
  <img src="https://img.shields.io/badge/license-MIT-green" alt="MIT License">
  <img src="https://img.shields.io/badge/Geometry%20Dash-2.2%20verified-orange" alt="Geometry Dash 2.2">
  <img src="https://img.shields.io/badge/GD%201.9-100%25%20implemented-success" alt="Geometry Dash 1.9 implementation">\n  <img src="https://img.shields.io/badge/GD%202.0-100%25%20implemented-success" alt="Geometry Dash 2.0 implementation">
  <img src="https://img.shields.io/badge/GD%202.1-100%25%20implemented-success" alt="Geometry Dash 2.1 implementation">
</p>

> ⚡ **Release candidate: v1.0.0**
>
> MuchoCore focuses on a clean server architecture, Geometry Dash protocol compatibility, and built-in protection against abusive traffic.

---

## ✨ Why MuchoCore?

| Area | What you get |
| --- | --- |
| 🎮 **Gameplay backend** | Accounts, profiles, levels, ratings, comments, social features, and cloud save |
| 🛡️ **MuchoProtect** | Centralized rate limiting, burst detection, account/IP isolation, and security auditing |
| 🔌 **Protocol focused** | Version-aware handling for modern Geometry Dash clients |
| 🧪 **Tested** | Automated protocol, routing, client-contract, security, Docker, and patcher checks |
| 🖥️ **Admin tools** | Web admin panel for managing the server and players |
| 🎵 **Music infrastructure** | Built-in public music storage and player uploads |
| 🚀 **Deployment ready** | Docker + Caddy setup with installation and update tooling |
| 🧩 **Extensible** | Organized PHP services, repositories, controllers, migrations, and API layers |

## 🛡️ MuchoProtect

Security is a first-class part of MuchoCore.

**MuchoProtect** sits in front of the router and evaluates protected requests before they reach the endpoint:

```text
Request
   ↓
MuchoProtect
   ↓
Identity + IP + Endpoint
   ↓
Rate / Burst Analysis
   ↓
ALLOW or BLOCK
   ↓
Legacy-compatible response
```

| Protection | Purpose |
| --- | --- |
| 🚦 **Per-endpoint limits** | Different limits for login, uploads, comments, scores, ratings, messages, and other sensitive actions |
| 🌐 **IP protection** | Limits abusive request bursts without exposing raw IP addresses in audit logs |
| 👤 **Account protection** | Uses a credential-bound identity fingerprint instead of trusting a public account ID alone |
| 💥 **Burst detection** | Catches short high-frequency request spikes typical of spam/abuse |
| 📋 **Audit events** | Records blocked security events with privacy-preserving hashed IP data |
| 🔒 **Protocol-safe blocking** | Uses the expected Geometry Dash failure body while preserving successful HTTP transport |

MuchoProtect is **enabled by default** and can be configured with environment variables.

## 🎯 Geometry Dash compatibility

MuchoCore has completed its server-side compatibility pass for **GD 1.9, 2.0, 2.1 and 2.2**. The GD 2.2 real-client gate is verified; older-generation runtime evidence remains tracked separately.

| Component | Status |
| --- | --- |
| Client version detection | ✅ Implemented |
| 2.2 authentication / GJP2 handling | ✅ Implemented |
| 2.2 profile state fields | ✅ Implemented |
| 2.2 level / leaderboard protocol work | ✅ Implemented |
| Version-aware endpoint behavior | ✅ Implemented |
| Protocol regression tests | ✅ Passing |
| Real 2.2 client trace fixture | ✅ Captured from GD 2.2.13 |
| GD 1.9 server-side protocol compatibility pass | ✅ **100% complete**; real-client verification pending |\n| GD 2.0 server-side protocol implementation | ✅ **100% complete**; real-client verification pending |
| GD 2.1 server-side protocol implementation | ✅ **100% complete**; real-client verification pending |

> **Compatibility note:** GD 2.0 and GD 2.1 server-side implementations are **100% complete** for the current protocol scopes. Their remaining gates are empirical verification with real clients; this is deliberately tracked separately from implementation completeness.

## 🧪 Validation & tests

MuchoCore is continuously checked through GitHub Actions and local test scripts.

| Check | Result |
| --- | :---: |
| PHP syntax / source validation | ✅ |
| Router compatibility | ✅ |
| Client compatibility checks | ✅ |
| Protocol matrix / 2.2 guards | ✅ |
| Protocol hash checks | ✅ |
| Client trace tooling | ✅ |
| GD 1.9 protocol surface / wire suite | ✅ |
| GD 2.0 protocol surface / wire suite | ✅ |
| GD 2.1 protocol surface / wire suite | ✅ |
| Social message / relationship wire suite | ✅ |
| GD 2.1 wire contract | ✅ |
| Client contract checks | ✅ |
| MuchoProtect security tests | ✅ |
| Python client patcher self-test | ✅ |
| Windows patcher validation | ✅ |
| Docker / Compose validation | ✅ |
| Caddy / routing validation | ✅ |

### Local checks

~~~bash
php tests/client/client-compatibility.php
php tests/protocol/protocol-matrix.php
php tests/protocol/protocol-19-surface.php
php tests/protocol/protocol-19-wire.php
php tests/protocol/protocol-20-surface.php
php tests/protocol/protocol-20-wire.php
php tests/protocol/protocol-21-surface.php
php tests/protocol/protocol-22-surface.php
php tests/protocol/router-compatibility.php
php tests/client/client-trace.php
php tests/security/muchoprotect.php
python3 tools/client/client-patch.py --self-test
bash tests/client/client-contract.sh
bash tests/release/release-2.2-gate.sh
# Release-only: run after capturing a real GD 1.9 client fixture.
bash tests/release/release-1.9-gate.sh
# Release-only: run after capturing a real GD 2.0 client fixture.
bash tests/release/release-2.0-gate.sh
# Release-only: run after capturing a real GD 2.1 client fixture.
bash tests/release/release-2.1-gate.sh
~~~

## 🚀 Getting started

### 1. Choose your setup

| Environment | Guide |
| --- | --- |
| 🐧 Linux VPS + root access | [docs/SETUP.md](docs/SETUP.md) |
| 🌐 Standard PHP hosting | [docs/SHARED_HOSTING.md](docs/SHARED_HOSTING.md) |
| 🎮 Client already patched / server running | [docs/CLIENT_SETUP.md](docs/CLIENT_SETUP.md) |
| 🧠 Advanced deployment / internals | [docs/ADVANCED.md](docs/ADVANCED.md) |

### 2. Verify the server

~~~text
https://YOUR-DOMAIN/health
~~~

Expected response:

~~~text
1
~~~

### 3. Open the admin panel

~~~text
https://YOUR-DOMAIN/admin/
~~~

The default administrator username is:

~~~text
admin
~~~

The password is set during installation.

## 🎮 Connecting Geometry Dash

For Windows clients, use:

~~~text
tools/client/client-patch.bat
~~~

The patcher creates a **separate client file** and does not replace the original EXE.

> ℹ️ A successful patcher run confirms that the known URL strings were replaced successfully. It does **not** by itself prove full compatibility with a specific Geometry Dash build.

## 🏗️ Project structure

~~~text
START_HERE.md
README.md
src/                       ← server logic
public/                    ← HTTP entry points and GD endpoints
database/                  ← migrations
tests/
├── client/                ← tracing, contracts and client checks
├── client-fixtures/       ← real-client contracts by version
├── protocol/              ← protocol and wire-format checks
├── security/              ← MuchoProtect and security checks
├── application/           ← application/domain contracts
├── integration/           ← smoke, load, regression and hosting
└── release/               ← release gates
tools/
└── client/                ← client patchers and trace tooling
docs/                      ← documentation
assets/brand/              ← brand source artwork
docker/                    ← Docker / Caddy configuration
~~~

## 🔐 Security basics

Never publish or commit:

~~~text
.env
config/cloudsave.key
storage/
.secrets/
~~~

Before removing an installation, read the warning in `uninstall.sh`: it removes the containers and database.

## 📦 Release candidate

MuchoCore **v1.0.0** is being prepared around one main goal:

> **Make the Geometry Dash 2.2 protocol surface reliable enough for a real release, then expand compatibility further without sacrificing maintainability or security.**

The 2.2 gate is based on **real Geometry Dash 2.2.13 client traffic**, not a synthetic fixture.

---

## 📄 License

MIT — see [LICENSE](LICENSE).
