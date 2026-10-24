# MuchoCore — Geometry Dash Private Server (GDPS)

**Open-source PHP server core for Geometry Dash.** Accounts, levels, comments, scores, social features, moderation, Cloud Save, admin tools, migration and client patching. One codebase with version-aware protocol handling.

[Website](https://muchogdps.space/muchocore/) · [Stable releases](https://github.com/IZKGMD/GMDmucho-core/releases) · [Changelog](CHANGELOG.md) · [Report an issue](https://github.com/IZKGMD/GMDmucho-core/issues)

> **Choose the correct package first.** The standard MuchoCore runtime requires **PHP 8.2+** (8.3+ recommended). The separate **5v.pl / PHP 7.0** build contains a native runtime, the compiled original application services and the original administration interface. It remains a development preview: complete real-client acceptance is **not certified**. See [native port coverage](docs/PHP7_NATIVE_PHASE1.md) and [release readiness](docs/V110_RELEASE.md).

## Choose your installation

| Hosting | Package / entry point | What you get |
| --- | --- | --- |
| **VPS (Linux)** | [One-command installer](#3-linux-vps--full-core) | Full PHP 8+ MuchoCore, Docker, MariaDB, Caddy, admin and worker |
| **Shared with PHP 8.2+** | [Shared installer / ZIP](#1-shared-hosting-php-82--full-core) | Full core and web admin; no Docker or SSH needed |
| **5v.pl / PHP 7.0** | [PHP7 preview artifact](#2-5vpl--php-70-experimental) | Native runtime, original admin, shared worker, browser client patcher and compiled services |

**Before installing:** Have a working HTTPS domain and database ready. Your hosting provider must allow requests from the Geometry Dash client; a working browser page alone is not proof that the game can connect.

## 1. Shared hosting (PHP 8.2+) — full core

**Requires:** PHP 8.2+ (8.3+ recommended), MySQL 8.0.29+ / MariaDB 10.4+, PDO MySQL, HTTPS, a database user, FTP or File Manager. For automatic bootstrap, PHP ZIP and outbound HTTPS to GitHub are also required.

### Option A — automatic FTP installation

1. Open **[MuchoCore Shared Installer](https://muchogdps.space/install/shared/)**.
2. Enter your FTP/FTPS host and credentials, public HTTPS domain, and MySQL credentials.
3. Let the installer detect the FTP mode, port and website root, or override them if your host requires it.
4. Finish the on-site database/admin wizard.
5. Verify `https://YOUR-DOMAIN/health` returns `1` and sign in at `https://YOUR-DOMAIN/admin/`.

The installer uses a published shared-hosting release. It does **not** make PHP 7 compatible.

### Option B — manual ZIP + FileZilla

1. Open [GitHub Releases](https://github.com/IZKGMD/GMDmucho-core/releases) and download **`MuchoCore-vX.Y.Z-shared-hosting.zip`** from a **published** release. Do **not** use GitHub's source-code ZIP; it may omit `vendor/`.
2. Create a **new empty database** and database user in your hosting panel.
3. Extract the archive locally. Upload the **contents of `muchocore/`**, not the outer folder, to the website's document root.
4. Check that FileZilla transferred hidden **`.htaccess`** and the `public/`, `src/`, `vendor/`, `database/` and `config/` directories.
5. Open **`https://YOUR-DOMAIN/install.php`** (not `/public/install.php`). Enter database details and choose the administrator password.
6. Verify `/health` returns `1`; open `/admin/` (initial user: `admin`).
7. Remove `public/install.php` and `public/shared-install.php` after setup. Back up the database, `.env` and the existing `config/cloudsave.key` securely.

**Updating an existing site?** Back up files and SQL first. **Do not** run first-time setup on a populated database or overwrite the existing `.env`, Cloud Save key or private settings. Follow the [full shared-hosting guide](docs/SHARED_HOSTING.md) for upgrades and provider-specific document roots.

[Detailed shared-hosting guide](docs/SHARED_HOSTING.md) · [Russian ZIP installation guide](docs/SHARED_INSTALL_RU.txt)

## 2. 5v.pl / PHP 7.0 (experimental)

This build runs entirely on shared hosting with its own MySQL connection, compiled PHP 7-compatible application services and English administration pages. It requires no VPS proxy, Composer installation or process-launch functions on the host. PHP 7 is obsolete; provider and real-device acceptance remain separate from the automated integration checks.

### Download the correct archive

1. Open **[PHP7 Native Preview — GitHub Actions](https://github.com/IZKGMD/GMDmucho-core/actions/workflows/php7-preview-archives.yml)**.
2. Select the latest **successful** run on `main`. Sign in to GitHub if artifact download requires it.
3. Under **Artifacts**, download **`MuchoCore-PHP7-5v-native-preview`** and extract the downloaded artifact ZIP.
4. Inside is **`MuchoCore-PHP7-5v-native-preview.zip`**. Extract this ZIP too; it contains `mc/`, `5V_ROOT_REWRITE_RULES.txt` and setup instructions.

**Do not choose** `MuchoCore-full-PHP82-shared-hosting` for a PHP 7 host. Workflow artifacts are temporary development builds, not stable releases.

### Update an existing 5v.pl test installation (keep all data)

1. **Back up** the existing `/mc/` directory and export its MySQL database via phpMyAdmin.
2. Upload the new archive's `mc/` files to the **same `/mc/` directory**. Preserve the live `/mc/config/php7-local.php`, `/mc/config/php7-news.json` (if used), `/mc/public/php7-mode.on` and all existing private data.
3. Sign in as the existing owner and open **Server**. Use **Install uploaded build**, then **Complete migration** if offered. The update verifies the uploaded core pack, creates a database backup and applies additive changes while preserving accounts and levels. Use **Update database** for older native schemas. Do not import a legacy database dump over this installation.
4. In the **domain root** `.htaccess` (not `/mc/.htaccess`), add the rules from `5V_ROOT_REWRITE_RULES.txt` just below `RewriteEngine On` and **before** catch-all rules. Keep your other rules. Your host must support these Apache rewrites.
5. Keep `site_url` in `/mc/config/php7-local.php` set to the HTTPS origin **without `/mc`**.
6. Test `https://YOUR-DOMAIN/mc/health`, then the game endpoints below. **Do not rerun `php7-setup.php` against the existing database.**

For a **brand-new disposable test database**, read the [PHP7 first-time setup](docs/PHP7_NATIVE_PHASE1.md): it uses `public/php7-setup.php`, a private setup key, HTTPS and an **empty** MySQL database. Delete the setup script after use and enable `public/php7-mode.on` only once setup succeeds.

### Feature switches (test systems only)

In your existing private `/mc/config/php7-local.php`, the relevant options are:

~~~php
'enable_accounts' => true,
'enable_registration' => true,
'enable_writes' => true,
'enable_social' => true,
'enable_cloudsave' => true,
~~~

These switches do **not** install missing features. Account and write functions are disabled in distributed defaults because PHP 7 is end-of-life. Do not post real DB passwords or `auth_pepper` online.

### Test routing and registration without creating an account

From Termux/Linux (replace the domain with yours):

~~~bash
curl -i -X POST \
  'https://YOUR-DOMAIN/a/accounts/registerGJAccount22.php' \
  --data-urlencode 'userName=bad!'
~~~

On the **current PHP7 handler**, a routed registration endpoint with enabled accounts and a working database returns **`-4`** for this deliberately invalid username. `-1` means the request was rejected; check the deployed `auth.php` / `runtime.php`, flags, PHP logs, MySQL schema and routing. HTTP `404` means the path was not found; HTTP `503` indicates a runtime/configuration/database failure. HTTP 200 alone does **not** prove registration works.

**Native coverage:** accounts, profiles, levels, scores, comments, votes, Cloud Save, private messages, friends and blocks; original admin pages, ratings, moderation, clans, quests, plugins, News media, backups and security tools. Shared hosting also has bounded queue processing, optional HTTPS webhooks, resumable search rebuilding, cron backups, passkeys and Cvolton account/profile/level/score import. See the coverage document for tested behavior and remaining acceptance work. PHP 8-only third-party plugins still need their own compatibility review.

[PHP7 installation and upgrade details](docs/PHP7_NATIVE_PHASE1.md) · [5v.pl rewrite/troubleshooting guide](patchers/shared-hosting/README.md)

## 3. Linux VPS — full core

**Requires:** Linux VPS, sudo/root, a domain with a DNS `A` record pointing to its public IPv4, and inbound TCP 80/443 for normal direct hosting. No manual PHP, database or Caddy installation needed.

1. Point `gdps.example.com` to the VPS IPv4 in your DNS panel.
2. Run the stable-release installer:

~~~bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install-remote.sh | sudo -E bash
~~~

3. Enter your domain and initial admin password when prompted. The installer sets up Docker, PHP, MariaDB, Caddy, workers, migrations and configuration.
4. Open `https://YOUR-DOMAIN/health` (expected `1`) and `https://YOUR-DOMAIN/admin/` (initial user: `admin`).
5. Patch a Windows/Android game client to point to the **domain root**.

Direct VPS HTTPS remains the default. No Cloudflare API token, Global API Key, Cloudflare Tunnel or Cloudflare Proxy is required for this path. The installer configures an already-active UFW to permit inbound TCP 80 and 443. For an optional tunnel, the API token needs **Cloudflare Tunnel -> Edit**; see [advanced networking](docs/ADVANCED.md).

**Maintain an existing VPS:**

~~~bash
sudo mucho logs
sudo /opt/mucho-core/bin/mucho-db-backup.sh
sudo /opt/mucho-core/update.sh
~~~

Take a verified backup before updating. The normal updater selects published stable releases, not arbitrary commits on `main`. For NAT/CGNAT or blocked 80/443, read about [Cloudflare Tunnel](docs/ADVANCED.md).

[VPS walkthrough](docs/GETTING_STARTED.md) · [Setup and backups](docs/SETUP.md)

## Connect Geometry Dash (after installation)

Installing the server **does not patch the game**.

1. For the **full PHP8 core**, open `https://YOUR-DOMAIN/admin/?page=clientpatcher` to patch a Windows executable, or follow [Client Setup](docs/CLIENT_SETUP.md) for the local `tools/client/client-patch.bat` method.
2. Enter only `https://YOUR-DOMAIN` as the server address — **not** `/database` or `/mc`. Some GD 2.209 builds require short compatible URL aliases because their executable URL slots have fixed lengths.
3. Keep the original executable/APK; test the newly patched client separately. Android APKs must be signed appropriately before distribution.
4. Check **login → level search → level download → test upload → comments → Cloud Save**. A patched file or healthy `/health` endpoint is not proof of real-client compatibility.

For the PHP7 shared build, open **Admin → Client Patcher**. Windows patching and resumable Android APK rebuilding/signing run on the shared host without shell tools. Preserve the original client and verify the output on your device. Browser/provider download restrictions and actual game connection still need acceptance on the target host.

[Windows & Android patching tutorial](docs/CLIENT_SETUP.md) · [Patcher downloads and tools](patchers/README.md) · [Version profiles](docs/VERSIONS.md) · [Real-client verification status](docs/CLIENT_COMPATIBILITY.md)

## Features and limitations

| Capability | Full PHP8 core | 5v.pl PHP7 preview |
| --- | --- | --- |
| Accounts, profiles, levels, scores, comments | Included | Native handlers and compiled services |
| Search, uploads, downloads, votes, local Cloud Save | Included | Native handlers; resumable search rebuild |
| Admin Panel, Rating Studio, RBAC, moderation | Included | Original templates with native identities and permissions |
| MuchoProtect, audit and operator tools | Included | Compiled services and shared-host operator pages |
| Clans, quests, plugins, migration tools | Included | Compiled services; bounded worker; verified Cvolton import |
| News and client patching | Included | News media and shared Windows/APK patcher |
| GD 2.209 | In development; **not fully certified** | Automated contracts pass; real-client acceptance pending |

The source tree's version is in [VERSION](VERSION). **A source version is not proof of a published stable release.** Download stable production builds only from [GitHub Releases](https://github.com/IZKGMD/GMDmucho-core/releases); the latest release can lag behind `main`. See [1.1.0 release gates](docs/V110_RELEASE.md).

## Migrate an existing GDPS

For the **full PHP8 core**, install MuchoCore into a **fresh target database**, then open **Admin → Tools → Migration Center**. Use a read-only connection to the legacy Cvolton/GMDprivateServer database, review the dry-run, create/verify a target backup, and only then apply migration. Never import a legacy SQL dump directly over an initialized MuchoCore database.

[Migration Kit](docs/MIGRATION_KIT.md) · [Migration Center](docs/MIGRATION_CENTER.md)

## Troubleshooting

| Symptom | Check first |
| --- | --- |
| `/health` does not return `1` | PHP/runtime, database connectivity, application logs |
| HTTP `404` on `/a/...php` | Domain-root `.htaccess` / web-server rewrite configuration |
| HTTP `503`, body `-1` | Missing PHP7 marker/config, PDO MySQL, database/schema or PHP error log |
| HTTP `200`, body `-1` | Endpoint rejected request; inspect exact route, flags and expected parameters |
| GD says **“Something went wrong”** | Check the actual registration/login endpoint, not only the health page |
| Levels appear but will not open | Test `downloadGJLevel22.php` independently of `getGJLevels21.php` |
| 5v.pl installer rejects PHP version | You used the **full PHP8 ZIP** on PHP7; use the separate test preview or upgrade hosting |
| Cloud Save fails after migration | Preserve the original encryption key; check POST body limits and database |

**Do not reset the database to fix a single endpoint.** Protect `.env`, `storage/`, `config/cloudsave.key`, `php7-local.php`, database credentials and signing keys. Never commit or share them.

## More documentation

- **[Quick start](START_HERE.md)** — installation overview
- **[Shared hosting](docs/SHARED_HOSTING.md)** — full PHP8 shared hosting, FileZilla, installer, requirements
- **[PHP7 native test port](docs/PHP7_NATIVE_PHASE1.md)** — 5v.pl setup, updates, feature switches
- **[VPS setup](docs/SETUP.md)** — DNS, installer, logging, backups and updates
- **[Client setup](docs/CLIENT_SETUP.md)** — patching Windows/Android clients
- **[GD version compatibility](docs/VERSIONS.md)** · **[client tests](docs/CLIENT_TESTING.md)**
- **[Migration](docs/MIGRATION_KIT.md)** · **[custom plugins](docs/CUSTOM_PLUGINS.md)**
- **[API specification](openapi.yaml)** · **[Changelog](CHANGELOG.md)**

## Development and contribution

~~~bash
git clone https://github.com/IZKGMD/GMDmucho-core.git
cd GMDmucho-core
composer test
~~~

Code lives in `src/`, public endpoints in `public/`, migrations in `database/`, validation in `tests/`, and patchers/build tooling in `tools/`. Contributions and reproducible bug reports are welcome. Do not post passwords, private client data or active exploit details in public issues.

**License:** [MIT](LICENSE).
