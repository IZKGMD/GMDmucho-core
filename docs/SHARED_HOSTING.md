# MuchoCore Shared Hosting

This is the no-VPS deployment mode for compatible PHP shared hosting. It does not require Docker, SSH, sudo, Composer, or a server terminal.

## Provider compatibility

The PHP installer requirements and the ability to run a Geometry Dash server are separate checks.

| Hosting type | Installation files | Geometry Dash client traffic |
| --- | --- | --- |
| Compatible shared hosting | Supported | Supported when normal app/API requests are allowed |
| InfinityFree Free | May pass the PHP/file checks | **Not compatible for a GDPS:** InfinityFree documents that its free hosting blocks programmatic access from mobile/desktop apps and game API clients |

InfinityFree Free currently advertises PHP 8.4 and MySQL 8.0 / MariaDB 11.4, but its free-host browser security system is designed for browser traffic rather than app/API traffic. A successful bootstrap or PHP preflight therefore must not be interpreted as proof that a Geometry Dash client can connect.

## Cloud Save encryption and backup safety

The shared-hosting installer writes the encrypted-save key to
`config/cloudsave.key` (private, mode 0600). The Cloud Save backend reads
that same key when `MUCHO_SHARED_HOSTING=1`, rather than trying to access
Docker's `/var/lib/muchocore/cloudsave.key` path. Key readers accept both the
canonical Base64 encoding and the legacy 64-character HEX encoding of the
same 32 random bytes; changing the text encoding never rotates the key.

**Back up this key securely together with database backups.** Database
backups alone cannot decrypt existing game saves. Never regenerate or delete
the key during upgrades. If the hosting provider requires another private
location, move the **same existing key** outside the web root and set the
absolute `MUCHO_CLOUDSAVE_KEY_FILE` path in `.env`.

On a VPS, the historical Docker key path is unchanged. Missing/invalid keys
cause Cloud Save to fail closed rather than silently creating incompatible
new ciphertext.

## Requirements

A shared-hosting account needs:

- PHP 8.2 or newer (PHP 8.3+ recommended);
- MySQL 8.0.29+ or MariaDB 10.4+;
- PDO MySQL;
- HTTPS;
- PHP ZIP support for the one-file bootstrap installer;
- outbound HTTPS access to GitHub;
- FTP or the hosting file manager;
- a database and database user.

## First administrator: one setup, one login

- **5v.pl (native PHP 7):** use the official 5v.pl installer, then open `https://YOUR-SITE.5v.pl/mc/admin.php`. On a fresh server, enter MySQL details, choose the admin username, email and password **once**. Future visits are ordinary sign-ins. If you use the optional key-based `/mc/public/php7-setup.php` wizard, it now creates the administrator at the same time as the database rather than sending you through another registration page.
- **Shared PHP 8.2+:** `/shared-install.php` asks for MySQL and final admin credentials together. On an empty database it creates the first owner directly. Then open `/admin/` and sign in — no Setup Secret or activation link.
- **VPS:** enter the username/password in the private SSH installer, then open `/admin/` to sign in.

Do not try to register a second first owner on an existing installation. The installer preserves existing credentials; use official recovery tools if you have lost them.

## Archive-based automatic installer — 5V.PL ONLY

New operators can install the **native PHP 7 preview directly from the release
ZIP**, without uploading files manually or using VPS/SSH. The build
`MuchoCore-PHP7-5v-native-preview.zip` includes:

- `START_INSTALL_WINDOWS.cmd` / `START_INSTALL_LINUX.sh` — runs the local
  Python 3.10+ installer (`INSTALL_5V.py`); no Python is required on 5v.pl.
- `START_RECOVERY_WINDOWS.cmd` / `START_RECOVERY_LINUX.sh` — FTP-verified
  **recovery of an EXISTING owner** (`RECOVER_ADMIN_5V.py`), even when the
  previous password or second factor has been lost. When exactly one owner
  exists, the operator may leave the owner username empty.
- `README_RU.txt` — plain-language first-run and recovery instructions.

The operator creates a site and an **empty MySQL database** at 5v.pl first,
extracts the whole archive and provides: site HTTPS URL, FTP access,
MySQL credentials, owner username/email/password. Those inputs are used
locally; the control-plane VPS never receives them. FTPS is the secure
default; opting into unencrypted FTP requires explicit acknowledgement.

The script verifies the website document root with a temporary random HTTPS
marker, **refuses any existing `mc/` directory**, uploads the native package,
checks that private `mc/config/` files are denied over HTTP **before**
uploading the MySQL password, safely backs up existing root `.htaccess`,
sets up the private PHP config, submits the real first-owner bootstrap
through HTTPS and advances the owner's original resumable database migration
without bypassing its locks. Installation is marked complete only after
the private migration completion marker is observed.

**Automatic client publishing (after the database/owner migration):**
the local installer looks for user-supplied `GeometryDash.exe` and
`GeometryDash.apk` in the extracted ZIP directory or `original_clients/`,
and can prompt for other local paths. The ZIP contains no copyrighted game
binaries. The **canonical SHA-pinned Python Windows patcher** retargets a
supported GD 2.209 EXE locally; the already-implemented native PHP7 patcher
accepts APK chunks over authenticated HTTPS, signs the patched APK with
v1+v2 using the **persistent private site key**
`mc/config/php7-apk-signer.json`, and reports SHA-256, package name and
certificate ID. Keep a secure backup of this key so APK updates retain their
signing identity.

**Only when the operator confirms they have redistribution permission**,
the publisher uploads the resulting files through the site owner's verified
FTP connection into `mc/public/clients/`. Client filenames include a
12-character SHA-256 prefix and are never overwritten; the installer checks
the FTP file size and public HTTPS availability, prints the direct EXE/APK
URLs, and creates a simple downloads index. The APK is moved from the
authenticated private patcher job into the public directory by FTP when
possible, avoiding a second full-sized copy on low-storage 5v.pl; otherwise
it is downloaded through the authenticated session and re-uploaded with
SHA-256 verification. This requires enough free space on the target.

If this stage fails, **the already installed admin, owner, database,
private keys and levels stay intact**. Re-run only
`START_CLIENTS_WINDOWS.cmd` / `START_CLIENTS_LINUX.sh`, log in with
the existing owner, and retry. The EXE requires the matching installed
game DLL/Resources; it is not a standalone game installer. No user is
authorized to publicly redistribute proprietary game files merely by
owning a personal copy.

**Recovering the owner:** verify FTP control of the same `*.5v.pl` site,
enter a new owner password and invoke the bundled recovery launcher.
It creates a temporary server-side PHP 7 handler containing a hash of
a random 256-bit authorization token, sends the token and new password
over HTTPS, resets only the selected existing active owner credential,
revokes old sessions, passkeys and MFA credentials, and deletes the
handler. No accounts, levels, data, privileges or Cloud Save keys
are deleted. Recovery works only while the user retains or recovers
their FTP account access. When multiple active owners exist, the
existing owner username is mandatory to avoid ambiguity.

**Limitations:** this is a new-install tool; it intentionally will not
overwrite an existing site/owner. Test on a fresh 5v.pl account before
calling native GD 2.209 compatibility certified. The target host may
not support FTPS; ordinary FTP does not encrypt passwords or upload
data. PHP 7 is obsolete, and the operator remains responsible for
keeping a separate MySQL backup and private Cloud Save key.

## Online installer: ONLY 5V.PL (PHP 7 native preview)

The dedicated entry point at [https://muchogdps.space/install/shared/](https://muchogdps.space/install/shared/)
is now **5V.PL ONLY**. It is not the generic PHP 8.2+ shared-hosting
installer and rejects requests for websites outside `https://name.5v.pl`.

5v.pl publicly advertises PHP 7.0.19, MySQL, FTP and `.htaccess`. The
normal PHP 8.2+ shared package **cannot** run there. The online installer:

1. Requires an HTTPS `*.5v.pl` website and the hosting account's FTP data.
   The control plane verifies the selected remote web root via a temporary
   HTTPS marker, including when the user enters an explicit FTP path.
2. Builds the existing PHP 7 native preview archive using
   `tools/release/build-php7-native.sh`, verifies its contents, and rejects
   PHP 8-only files. This is a separate runtime with incomplete parity.
3. Uploads to a **fresh `mc/` directory only**. It refuses to overwrite
   existing `mc/` installations and does not modify an existing website
   `.htaccess`.
4. After FTP upload, the page shows **Open admin setup wizard**. Create an empty MySQL database in your 5v.pl control panel, then click the button. The setup key is passed privately through the URL fragment, filled automatically, and removed from the address bar. Enter MySQL details and the final administrator username, email, and password once. No PHP config file editing or SSH is required. The job remains **files uploaded / setup required** until the owner completes this step.
5. Requires adding the exact routing rules from
   `5V_ROOT_REWRITE_RULES.txt` to the WEBSITE ROOT `.htaccess`, then testing
   `/health`, authentication and the actual Geometry Dash 2.209 client.

**Credentials:** the central installer collects an FTP password, *not*
MySQL or administrator credentials. The latter are entered directly on the
tenant's HTTPS origin during browser setup. Job secrets are temporary and
never printed in logs. FTPS should be preferred where the provider supports
it; plain FTP is unencrypted. 5v.pl does not advertise SFTP support.

**Limitations:** PHP 7 is end-of-life. The native preview has not been
certified for every GD 2.209 action or actual game UI. Upload success must
not be advertised as a complete game-server installation. A PHP 8.2+
hosting provider should use the **manual generic shared-hosting package**
below, not the 5v.pl-only entry point.

## Fastest path: FTP bootstrap

For a new shared-hosting site, the simplest flow is:

~~~text
FTP / File Manager
        ↓
upload ftp-install.php
        ↓
open https://YOUR-DOMAIN/ftp-install.php
        ↓
resolve latest published stable release
        ↓
verify the release SHA-256 digest
        ↓
extract the shared-hosting package
        ↓
open /shared-install.php
        ↓
database + admin setup
~~~

Download public/ftp-install.php from the MuchoCore repository and upload only that file into a **fresh directory dedicated to the GDPS**.

The bootstrap intentionally refuses to run when it finds an existing .env, .htaccess, composer.json, composer.lock, src/, database/, public/, vendor/, config/, or storage/ path. This prevents it from silently overwriting another application.

Open the bootstrap over HTTPS:

~~~text
https://YOUR-DOMAIN/ftp-install.php
~~~

The bootstrap does not collect or transmit your FTP username or FTP password. FTP is only the delivery method used to put the bootstrap file on your hosting account.

The bootstrap resolves the latest **published stable GitHub release**, selects:

~~~text
MuchoCore-vX.Y.Z-shared-hosting.zip
~~~

and verifies the release asset's SHA-256 digest before extraction. It also checks the archive structure and embedded VERSION value before copying files into the target directory.

After a successful extraction, the bootstrap removes itself when the filesystem allows it and redirects to:

~~~text
https://YOUR-DOMAIN/shared-install.php
~~~

The normal shared-hosting installer then performs the database and application setup.

## One archive + browser installer (recommended for manual FTP)

A full shared-hosting ZIP already contains the MuchoCore source, production
`vendor/`, migrations, GD endpoints, admin and both Apache routing files.
It also includes **`public/install.php`**, a PHP 7-readable preflight that
never parses the full core on an incompatible host.

1. Extract `MuchoCore-vX.Y.Z-shared-hosting.zip` locally.
2. Upload the **contents of its `muchocore/` directory** to your site's
   document root, including the hidden root `.htaccess`.
3. Configure an empty MySQL/MariaDB database in your host's control panel.
4. Open `https://YOUR-DOMAIN/install.php`. The root Apache rewrite directs
   it to `public/install.php`; a custom public document root serves it
   directly.
5. On PHP 8.2+, the preflight delegates to the actual database/admin
   installer at `public/shared-install.php`. No second ZIP or separate VPS
   runtime is needed.
6. After setup, confirm `/health` and `/admin/`, then delete both public
   installer PHP files and preserve the Cloud Save key.

If the shared host only offers PHP 7 (such as the advertised 5v.pl
environment), the preflight displays a **blocked** page without collecting
credentials or writing to the DB. This package is a true native shared-host
distribution for PHP 8.2+, **not** a complete PHP 7 port.

A Russian quick start is bundled in `docs/SHARED_INSTALL_RU.txt`. CI publishes
a short-lived development ZIP artifact for testing after pushes to `main`;
stable releases retain their separate endpoint certification gate.

## Manual shared-hosting package

The stable GitHub release also publishes a complete package:

~~~text
MuchoCore-vX.Y.Z-shared-hosting.zip
~~~

This package already contains:

- vendor/ with production Composer dependencies;
- the MuchoCore PHP source;
- database migrations;
- the shared-hosting browser installer;
- the root and public Apache routing files;
- shared-hosting documentation.

Use the package directly when you prefer to upload the complete tree yourself.

Do not treat a normal source archive as the shared-hosting package: development/source archives may not contain vendor/.

## Project layout

The completed shared-hosting installation should look like:

~~~text
muchocore/
├── public/
├── src/
├── database/
├── config/
├── vendor/
├── storage/
├── composer.json
├── composer.lock
├── .env
├── .htaccess
└── ...
~~~

There are two supported Apache layouts.

### Option A — public document root

Point the hosting document root directly at:

~~~text
.../muchocore/public
~~~

### Option B — project-root document root

If the hosting provider does not allow a custom public document root, point the domain at the project root. The root .htaccess routes public requests into public/.

The second layout is the default for the one-file FTP bootstrap because it can be installed into an ordinary shared-hosting web directory.

## Database setup

Create a new empty MySQL/MariaDB database and user in the hosting control panel.

The installer needs:

~~~text
DB host
DB port
DB name
DB user
DB password
~~~

For a first installation, the target database must be empty.

The shared installer refuses to modify a non-empty database that is not already recognized as a MuchoCore installation.

## Browser installer

Open:

~~~text
https://YOUR-DOMAIN/shared-install.php
~~~

The installer checks:

- PHP version;
- required PHP extensions and functions;
- required MuchoCore files;
- writable project and storage directories;
- installer session support;
- available backup space.

After you submit the database and administrator settings, it:

1. connects to MySQL/MariaDB;
2. checks the database server version;
3. probes real DDL/DML permissions with a temporary table;
4. creates a verified target backup;
5. verifies the backup checksum;
6. runs all pending MuchoCore migrations;
7. creates or updates the administrator;
8. verifies the final schema and database connection;
9. writes the installation lock marker.

If a safety check fails, the installer stops instead of continuing with an unsafe database operation.

## Verify the installation

Open:

~~~text
https://YOUR-DOMAIN/health
~~~

Expected response:

~~~text
1
~~~

Then open:

~~~text
https://YOUR-DOMAIN/admin/
~~~

The default administrator username is:

~~~text
admin
~~~

The password is the one entered during installation.

## Existing GDPS migration

Do not use the old GDPS database as the initial MuchoCore target database.

Create a fresh MuchoCore database first. After the installation is healthy, use the Migration Center:

~~~text
https://YOUR-DOMAIN/admin/?page=migration
~~~

The shared-hosting migration flow keeps the source database read-only and performs the target-side safety work before importing.

The general flow is:

~~~text
Legacy GDPS database
        ↓
Read-only source connection
        ↓
Schema detection
        ↓
Preview / counts
        ↓
Fresh verified target backup
        ↓
MuchoCore migrations
        ↓
Transactional import
        ↓
Result verification
~~~

The first-class legacy datasets include accounts, profiles, levels, classic scores, and Platformer scores. Additional legacy datasets may be detected without being claimed as automatically migrated.

Compatible password hashes can be preserved. Unsupported legacy password formats are not guessed or exposed; the account is marked for password recovery instead.

## Security notes

Do not leave installer files publicly accessible after deployment.

The shared installer attempts to remove:

~~~text
public/shared-install.php
~~~

when possible, and the lock marker prevents a completed installation from being executed again.

After setup, verify that temporary bootstrap files are gone and keep:

~~~text
.env
storage/
config/cloudsave.key
~~~

protected from direct web access.

The root and public .htaccess files are part of the shared-hosting routing design. Review any provider-specific Apache rules before merging them into an existing site.

## Shared-hosting limitations

Shared hosting does not provide VPS-only functionality such as:

- Docker;
- systemd timers;
- root/sudo operations;
- server-side shell management;
- VPS-specific restore and service-control commands.

The normal GDPS HTTP API, Admin Panel, Cloud Save, migrations, backup verification, and MuchoProtect runtime can operate in shared-hosting mode, subject to the hosting provider's PHP, database, filesystem, execution-time, memory, and storage limits.

## Troubleshooting

Check in this order:

~~~text
/health
↓
PHP version
↓
PHP extensions
↓
PHP ZIP / cURL or allow_url_fopen
↓
DB host / port / name / user / password
↓
filesystem permissions
↓
hosting PHP error log
~~~

Do not expose these paths through the browser:

~~~text
src/
vendor/
storage/
.env
config/
database/
~~~

For a provider that cannot supply PHP ZIP support or outbound HTTPS access, use the manually uploaded shared-hosting package instead of the one-file bootstrap.
