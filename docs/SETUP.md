# MuchoCore VPS Setup

This guide is for a Linux VPS with root access or sudo.

## Requirements

- Linux VPS;
- root or sudo access;
- a domain pointing to the VPS;
- inbound ports 80 and 443 for normal HTTPS mode.

You do not need to install PHP, MariaDB or Caddy manually.

## 1. Point your domain to the VPS

Create a DNS record:

~~~text
gdps.example.com → YOUR-VPS-IP
~~~

Replace the example domain with your own.

## 2. Run the installer

The recommended path is:

~~~bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install-remote.sh | sudo bash
~~~

For a **brand-new interactive VPS installation**, the very first step
asks for the **administrator username, password and password confirmation**
in your private terminal. Next you choose a GDPS name and domain. MuchoCore
generates database and Cloud Save secrets internally, runs migrations and
creates the owner automatically; there is **no setup key, registration link,
or extra activation page** to manage. Once the installer finishes, open
`https://YOUR-DOMAIN/admin/` and sign in with the password you chose.

For an **existing installation**, the installer preserves the owner and never
asks you to create a second account or overwrite its password. If an older
installation was interrupted before browser activation, its existing protected
recovery flow is preserved. Check the owner state using `sudo mucho admin`.

**Unattended installations** must supply a private `MUCHO_ADMIN_PASSWORD`
(and optionally `MUCHO_ADMIN_USER`) environment variable. Avoid command-line
arguments or public build logs containing passwords. Ordinary updates cannot
reopen enrollment. For intentional recovery, use verified-email recovery or
an explicit console reset after checking backups. See
[First-run registration](ADMIN_FIRST_RUN.md).

If the GDPS does not start or the admin page cannot be reached, use `sudo mucho doctor`. It checks container state, local and public HTTPS health, DNS resolution, migration availability, and whether an active owner exists. **A failed public health check now fails the diagnosis instead of printing “No problems found.”** It does not erase your database.

Everything else uses production-safe defaults. The default compatibility profile is **all supported generations** and can be changed later with:

~~~bash
sudo mucho config
~~~

For unattended deployment:

~~~bash
sudo ./install --domain=gdps.example.com --gd-versions=19,22
~~~

The installer prepares:

- MariaDB;
- PHP 8.3;
- Caddy;
- the MuchoCore database;
- Cloud Save keys;
- a first administrator created from privately entered console credentials;
- the selected Geometry Dash compatibility profile;
- release-based automatic updates.

The production Compose file contains only the live GDPS services. The integration-test tenant is isolated in **[docker-compose.test.yml](../docker-compose.test.yml)**.

## 3. Verify the server

MuchoCore intentionally keeps both public HTTP and HTTPS available on a standard VPS deployment. Legacy Geometry Dash clients can use plain HTTP, while HTTPS is available for modern browsers and protected administrator sessions.

Open:

~~~text
http://YOUR-DOMAIN/health
~~~

Expected response:

~~~text
1
~~~

Then verify HTTPS:

~~~text
https://YOUR-DOMAIN/health
~~~

It should also return:

~~~text
1
~~~

The Admin Panel is forced to HTTPS when reached directly over HTTP.

Then open:

~~~text
https://YOUR-DOMAIN/admin/
~~~

On the first visit, sign in with the credentials already set in your SSH
installation. No additional Setup Secret is needed. Normal upgrades do not
overwrite those credentials.

## 4. Connect Geometry Dash

After `/health` works, follow `CLIENT_SETUP.md` for the client patching/connection flow.

## PHP runtime security baseline (21 October 2026)

PHP fixed two critical runtime vulnerabilities (CVE-2026-109618 and
CVE-2026-109619) in **PHP 8.3.36** (also 8.2.35, 8.4.27 and 8.5.12).
MuchoProtect is application-level protection and **cannot** patch PHP itself.

Check the running versions of **both** production containers:

~~~bash
cd /opt/mucho-core
sudo docker compose exec -T app php -v
sudo docker compose exec -T worker php -v
~~~

On a standard PHP 8.3 deployment, any version **older than 8.3.36** must be
updated promptly. Back up the database first, then pull and rebuild a patched
base image and recreate the containers:

~~~bash
sudo /opt/mucho-core/bin/mucho-db-backup.sh
cd /opt/mucho-core
sudo docker compose build --pull app worker
sudo docker compose up -d --force-recreate app worker
sudo docker compose exec -T app php -v
sudo docker compose exec -T worker php -v
curl -fsS https://YOUR-DOMAIN/health
~~~

The Dockerfile now rejects a PHP image below the 21 October 2026 baseline
at **build time**, and `sudo mucho doctor` checks the currently running app
and worker versions after the change reaches your installation. A rebuilt image
can still contain other vulnerabilities; continue following PHP security
releases and apply ordinary image/OS updates.

The experimental 5v.pl PHP **7** native preview cannot be made safe against
these PHP-engine CVEs by updating MuchoCore or configuring MuchoProtect. PHP 7
is end-of-life; use a host with a supported, patched PHP release for production.

Official PHP announcement: https://www.php.net/archive/2026.php

## Updating

Use the built-in operator command:

~~~bash
sudo mucho update
~~~

Updates preserve the selected compatibility profile and Cloudflare Tunnel deployment mode.

The direct deployment script remains available for advanced troubleshooting:

~~~bash
sudo /opt/mucho-core/update.sh
~~~

### Release-based automatic updates

MuchoCore checks GitHub Releases on the VPS. A new **published stable release** triggers the automatic updater. Ordinary commits on `main`, drafts and prereleases are ignored.

The updater then runs the normal `update.sh` deployment path, which fetches the exact release tag and never deploys directly from `main`.

For manual deployment/testing, the same path remains available:

~~~bash
sudo /opt/mucho-core/update.sh
~~~

## Logs

Use the operator CLI:

~~~bash
sudo mucho logs
~~~

For live logs:

~~~bash
sudo mucho logs --follow
~~~

The low-level Docker command remains available when needed:

~~~bash
cd /opt/mucho-core
sudo docker compose logs --tail=100
~~~
~~~

## Backups

Before major changes, create a database backup:

~~~bash
sudo /opt/mucho-core/bin/mucho-db-backup.sh
~~~

Before relying on a database dump, verify the complete archive, SHA-256
sidecar and gzip SQL stream without executing any SQL:

~~~bash
php /opt/mucho-core/bin/mucho-verify-backup.php /path/to/backup.sql.gz
~~~

A result of `BACKUP_VERIFIED` verifies file integrity, not that a particular
server is restorable. CI also restores a representative database into a fresh,
isolated MariaDB schema and checks UTF-8, binary data, foreign keys, generated
columns and views. **Do not test restores against a production GDPS.** Retain
a protected copy of the database dump, its checksum and the Cloud Save
encryption key for disaster recovery.

Keep the Cloud Save secret safe:

~~~text
/opt/mucho-core/.secrets/cloudsave_key
~~~

It is mounted into the app as a Docker secret and is required to preserve
existing cloud save data across container rebuilds and updates.

### Disaster recovery requires more than the SQL dump

**A SQL backup alone is not a complete GDPS restore.** Preserve three
separate items in a restricted, preferably encrypted off-server backup:

1. The verified database archive **and its SHA-256 sidecar**.
2. The exact Cloud Save key at `/opt/mucho-core/.secrets/cloudsave_key`
   (or the configured `MUCHO_CLOUDSAVE_KEY_FILE`). Never generate a new key
   when restoring an existing server; old encrypted saves require the original.
3. Uploaded MP3 files in `storage/music-public/` and any other user-uploaded
   filesystem assets that are not stored directly in MariaDB.

**Do not commit, publish, attach or send this material in chat or GitHub.**
Restrict file ownership and permissions; keep at least one separately held
offline/off-server copy. Protect files and the key using an established backup
system with encryption and tested access controls.

The PHP 8 CI disaster-recovery test now restores the *actual migrated schema*
to a separately created disposable database and verifies game accounts,
profiles, levels, song **metadata**, administrator password hashes and
encrypted Cloud Save recovery with the original key. It also verifies that
the wrong key fails and that schema migrations remain idempotent.

This proves SQL/key compatibility, **not** recovery of uploaded MP3 bytes,
your external backup storage, DNS/TLS settings, or a real VPS. Validate those
separately before treating the production installation as recoverable.

### PHP 8: verified uploaded-MP3 snapshot (separate from SQL)

For a consistent disaster-recovery set, pause music uploads/deletions while
capturing **both** the SQL dump and music-file snapshot. SQL rows contain song
metadata and URLs; uploaded MP3 bytes are stored in `storage/music-public/`.
The snapshot tool copies only the expected random-name MP3 files, records
SHA-256 and byte counts, rejects symbolic links/unexpected entries, verifies
the new snapshot, and never overwrites an existing destination.

On the **VPS host** (not inside the container), after making a SQL backup:

~~~bash
sudo install -d -m 700 /var/backups/muchocore-assets
sudo php /opt/mucho-core/bin/mucho-music-snapshot.php create \
  /opt/mucho-core/storage/music-public \
  /var/backups/muchocore-assets/music-YYYYMMDD
sudo php /opt/mucho-core/bin/mucho-music-snapshot.php verify \
  /var/backups/muchocore-assets/music-YYYYMMDD
~~~

Choose a fresh date or unique suffix for each snapshot. Keep this directory
together with the **matching** verified SQL backup and the **separately
protected original** Cloud Save key in encrypted, access-controlled off-server
storage. A SHA-256 manifest detects accidental changes but is not a digital
signature or a replacement for trusted backup storage.

For a recovery drill, restore **only to a new, absent directory**:

~~~bash
sudo php /opt/mucho-core/bin/mucho-music-snapshot.php restore \
  /var/backups/muchocore-assets/music-YYYYMMDD \
  /opt/mucho-core/storage/music-recovery-review
~~~

Compare this directory with the restored `songs` metadata, then restore
required owner/group/modes and switch paths in a planned maintenance window.
The script never replaces the live `music-public` directory, updates database
rows, manages Cloud Save keys, or claims that a native client can play the files.
Do not declare disaster recovery complete until the actual restored GDPS
serves the uploaded song bytes and real clients can play them.

## Removal

`uninstall.sh` removes the installation and its Docker database volume.

It requires an explicit `DELETE` confirmation.

## Advanced VPS setups

NAT/CGNAT VPS deployments and Cloudflare Tunnel are documented in `ADVANCED.md`.
