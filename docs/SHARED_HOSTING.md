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
Docker's `/var/lib/muchocore/cloudsave.key` path.

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

- PHP 8.3 or newer;
- MySQL 8.0.29+ or MariaDB 10.4+;
- PDO MySQL;
- HTTPS;
- PHP ZIP support for the one-file bootstrap installer;
- outbound HTTPS access to GitHub;
- FTP or the hosting file manager;
- a database and database user.

## Automatic installation from MuchoGDPS

The official MuchoGDPS installer page can also connect to a shared-hosting account directly:

~~~text
https://muchogdps.space/install/shared/
~~~

Enter the FTP/FTPS host, username, password, public HTTPS URL, and database credentials. FTP mode, port, and the remote web root default to **Auto Detect**; you only need to override them when your provider requires a specific value. MuchoGDPS then downloads the latest published stable shared-hosting package on the control-plane server, verifies its SHA-256 digest, uploads the package through FTP/FTPS, starts the existing shared-hosting installer through the target site's HTTPS endpoint, and verifies the /health endpoint.

FTP and database passwords are stored only in the temporary deployment job on the MuchoGDPS control plane. They are not written to the deployment log and are deleted when the job finishes.

The target web root must already exist and the domain must already serve valid HTTPS. The shared hosting must permit normal PHP application/API traffic after installation.

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
