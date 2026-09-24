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
  <img src="https://img.shields.io/badge/Geometry%20Dash-2.2%20focused-orange" alt="Geometry Dash 2.2">
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

MuchoCore is currently focused on **Geometry Dash 2.2 compatibility** while keeping the protocol layer version-aware.

| Component | Status |
| --- | --- |
| Client version detection | ✅ Implemented |
| 2.2 authentication / GJP2 handling | ✅ Implemented |
| 2.2 profile state fields | ✅ Implemented |
| 2.2 level / leaderboard protocol work | ✅ Implemented |
| Version-aware endpoint behavior | ✅ Implemented |
| Protocol regression tests | ✅ Passing |
| Real 2.2 client trace fixture | 🔬 Final validation step |

> **Compatibility note:** automated coverage is extensive, but a final 100% compatibility claim still requires a real Geometry Dash 2.2 client trace to be captured and committed as a regression fixture.

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
| Client contract checks | ✅ |
| MuchoProtect security tests | ✅ |
| Python client patcher self-test | ✅ |
| Windows patcher validation | ✅ |
| Docker / Compose validation | ✅ |
| Caddy / routing validation | ✅ |

### Local checks

~~~bash
php tests/client-compatibility.php
php tests/router-compatibility.php
php tests/client-trace.php
php tests/muchoprotect.php
python3 tools/client-patch.py --self-test
bash tests/client-contract.sh
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
tools/client-patch.bat
~~~

The patcher creates a **separate client file** and does not replace the original EXE.

> ℹ️ A successful patcher run confirms that the known URL strings were replaced successfully. It does **not** by itself prove full compatibility with a specific Geometry Dash build.

## 🏗️ Project structure

~~~text
START_HERE.md      ← start here if you are new
README.md          ← project overview
src/               ← server logic
public/            ← HTTP entry points and GD endpoints
database/          ← migrations
tests/             ← automated tests
tools/             ← development / client tools
docs/              ← detailed documentation
docker/            ← Docker / Caddy configuration
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

The remaining 2.2 gate is based on **real client traffic**, not a synthetic fixture.

---

## 📄 License

MIT — see [LICENSE](LICENSE).
