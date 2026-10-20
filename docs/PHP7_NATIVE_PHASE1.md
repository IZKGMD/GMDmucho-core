# MuchoCore Shared / PHP 7 native runtime — Phase 1 (experimental)

**Status: incomplete, disabled by default, never advertised as a complete GDPS.**

This is the first **native PHP 7.0-compatible** code path in the *same*
MuchoCore shared-hosting ZIP. Unlike `5v-edge.php`, it does not forward
requests to a VPS. It connects **directly** to the operator's own MySQL
database using PDO, reads the existing MuchoCore tables, and encodes GD
public level lists on the host. It does not load any PHP 8 application
classes, Composer packages or custom extensions.

## Phase 2 — native disposable database installer and accounts

An experimental standalone browser wizard now exists at
`public/php7-setup.php`. It is **not** the main `/install.php` and does
not turn the modern PHP8 core into a PHP7 core. To prevent drive-by remote
installation it requires HTTPS, a previously placed strong private
`config/php7-setup-key.txt`, CSRF token and an **empty, disposable MySQL
database**. It creates only the native adapter's base roles, accounts, profiles,
songs, levels, and login-throttle tables. Never point it at an existing GDPS.
MySQL DDL cannot roll back; on interruption use a new empty test database.
After setup it writes `config/php7-local.php` privately and removes the
setup key. Delete `public/php7-setup.php` after testing.

The optional `compat/php7/auth.php` implements registration and login with
bcrypt-hashed passwords, the existing GD GJP2 derivation, profile creation in
a transaction, banned-account checks and DB-backed login/registration limits.
**Both account switches default to FALSE** in the generated private
configuration. Do not turn them on for public players: PHP 7.0 is unpatched,
real 5v.pl acceptance/security has not been established, and these functions
are isolated technical tests, not a certified account service.

To enable routing on a private throwaway site, the existing
`public/php7-mode.on` must still be created separately. This feature remains
off by default in the ZIP. To test accounts, explicitly enable
`enable_accounts` and `enable_registration` in the private config; disable
immediately after testing. The `/install.php` production installer continues
to reject PHP 7.

The MariaDB CI test `tests/compat/php7-native-mysql.php` independently creates
an isolated temporary database and tests native registration, login,
2.209 public search/download and repeat-install refusal. The PHP 7.0 CLI CI
job checks syntax, while the PHP 8.2-8.5 job tests security contracts.

**Now experimental:** level uploads/updates with credential verification, owner-bound
SQL updates and a 200 KiB levelString cap, gated by both
`enable_accounts` and `enable_writes` (both OFF by default). This is
not full GD 2.209 editor parity; large levels, revisions, permissions,
telemetry and advanced validation still need work.

**Not complete:** no production-certified levels.upload, comments, user cloud save, full GD 2.209
features, admin, full modern migrations/plugins or real client acceptance.

## Implemented

- PHP 7.0-syntax runtime: `compat/php7/runtime.php`.
- Experimental HTTP front controller: `public/php7.php` (disabled by
  default, requires HTTPS, explicit opt-in marker and local DB config).
- GET `/health` or `/checkIfServerOnline` checks *native DB connection*,
  not an external upstream.
- Public POST `/downloadGJLevel{19,20,21,22}.php` read-only level downloads, only publicly visible levels (no private, friend-only or daily negative IDs), GD 2.209 metadata/hash byte parity.
- Public POST `/getGJLevels{19,20,21,22}.php` search/list aliases,
  conservative public types 0,1,2,6,11,15,16,17 with some filters.
- Wire-byte regression parity with the modern
  `GdLevelListEncoder`, including GD 2.209 `binaryVersion=51` timestamps.
- Unknown, private, permissioned and write endpoints refuse with `-1`.
  This is intentional until authentication and DB mutation semantics are
  independently audited.
- No automatic activation, no database creation, no credentials in the
  shared ZIP. It is an **in-progress read-only compatibility adapter**.

## What is *not* available

Default-off experimental account registration/login are implemented but not certified; password recovery, level upload,
advanced level downloads, comments, leaderboard, friends, Cloud Save, 2.209 social,
admin, migrations, plugins and WebAuthn. Full PHP7 feature parity, complete
installation, provider acceptance and actual GD 2.209 client acceptance are
**NOT** established. `/install.php` still blocks PHP 7 to prevent a false
complete installation. Do not replace that guard with an unchecked shortcut.

The adapter expects a **pre-existing MuchoCore SQL schema**, with `levels`,
`accounts`, `profiles`, and `songs`. It is not a turnkey PHP7 installation.

## Opt-in for controlled tests only

On a throwaway, isolated test host/database with a dedicated user that has
**SELECT-only** privileges on the four tables, copy
`compat/php7/config.example.php` to `config/php7-local.php` and fill in
the local MySQL connection details. Create `public/php7-mode.on` as an empty
file to enable Apache routing. The root `.htaccess` blocks direct reads of
`config/` and `compat/`, and `public/.htaccess` blocks direct reads of the
marker. Only use HTTPS. A shared host that does not honor these rules is NOT
acceptable. Remove the marker to disable the adapter immediately.

Do not publish PHP7-hosted user credentials: PHP 7.0 has been without
security fixes for many years. This experimental adapter intentionally
disables all credential processing; keep it private until separately reviewed.

Test:
```sh
php tests/compat/php7-native-pure.php
php tests/compat/php7-native-parity.php
php tests/compat/php7-native-download-parity.php
```

GitHub Actions also runs native syntax and route tests inside an isolated
`php:7.0-cli` Docker image with networking disabled, plus main PHP8.2+
wire parity. These tests do not certify live shared hosting.

For the fully working shared-hosting GDPS with browser installer today,
use **PHP 8.2 or newer** and `/install.php` in the same ZIP.

Next milestones: native installer/schema management, account credentials,
level downloads, upload/write integrity, comments, social, admin and GD 2.209
client certification. Track issue #148.

## PHP7 native News — experimental (GD 2.209)

The same shared archive now includes `compat/php7/news.php` and the editable
`compat/php7/news.example.json`. The runtime serves the native
`/gd-content/news/news.dat`, `/gd-content/news/news_version.txt` and direct
`/news/*` diagnostic aliases using the genuine GZIP/Base64URL GD 2.209
format. There is no HTTP proxy or dependency on the MuchoCore VPS.

To try it on a **private, disposable test GDPS**:
1. Copy `compat/php7/news.example.json` to `config/php7-news.json`
   (outside the website's public folder).
2. Replace its example title/body with your own text. Bump the integer
   `version` every time you update a post; keep it above 1,000,000.
3. In private `config/php7-local.php`, set
   `'enable_native_news'=>true` and
   `'site_url'=>'https://YOUR-OWN-5V-DOMAIN.5v.pl'`.
4. Read the plain version at `/news/news_version.txt`; decode the content
   at `/news/news.dat` only when checking protocol bytes.
5. POST to `/a/getCustomContentURL.php`: expected result is
   `https://YOUR-OWN-5V-DOMAIN.5v.pl/gd-content`.

**Limitation:** the custom content prefix may also be used for GD music and
SFX. This PHP7 preview handles *news* only; other CDN content needs a
separately tested safe asset route. Do not enable it on a public server until
that path and the real client have been verified. Image/video elements and
in-game native runtime acceptance remain pending. Text/link-only news is
supported, but the PHP8 News admin cannot be run under PHP7.

For PHP8 VPS News, use `MUCHO_NATIVE_NEWS=1` and verify actual container
settings via `docs/NEWS_BOARD.md`. The new VPS-only read-only diagnostics:
`php tools/diagnostics/gd2209-feature-check.php`.
