# MuchoCore Shared / PHP 7 native runtime — Phase 1 (experimental)

**Status: incomplete, disabled by default, never advertised as a complete GDPS.**

This is the first **native PHP 7.0-compatible** code path in the *same*
MuchoCore shared-hosting ZIP. Unlike `5v-edge.php`, it does not forward
requests to a VPS. It connects **directly** to the operator's own MySQL
database using PDO, reads the existing MuchoCore tables, and encodes GD
public level lists on the host. It does not load any PHP 8 application
classes, Composer packages or custom extensions.

## Implemented

- PHP 7.0-syntax runtime: `compat/php7/runtime.php`.
- Experimental HTTP front controller: `public/php7.php` (disabled by
  default, requires HTTPS, explicit opt-in marker and local DB config).
- GET `/health` or `/checkIfServerOnline` checks *native DB connection*,
  not an external upstream.
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

Native GD account registration and login, password recovery, level upload,
level downloads, comments, leaderboard, friends, Cloud Save, 2.209 social,
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
```

GitHub Actions also runs native syntax and route tests inside an isolated
`php:7.0-cli` Docker image with networking disabled, plus main PHP8.2+
wire parity. These tests do not certify live shared hosting.

For the fully working shared-hosting GDPS with browser installer today,
use **PHP 8.2 or newer** and `/install.php` in the same ZIP.

Next milestones: native installer/schema management, account credentials,
level downloads, upload/write integrity, comments, social, admin and GD 2.209
client certification. Track issue #148.
