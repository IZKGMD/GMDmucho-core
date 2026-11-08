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

The installer keeps first-run interaction deliberately small:

~~~text
1. GDPS domain
2. Admin password
3. Confirm admin password
~~~

The initial admin password must contain at least 10 characters and is stored
only in the VPS's protected `.secrets/admin_password` file. When the installer
is run again and that secret already exists, it explicitly asks whether to
keep the existing password or enter and confirm a new one. For unattended
installs, pass `MUCHO_ADMIN_PASSWORD` through the environment.

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
- the administrator account;
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

The default administrator username is:

~~~text
admin
~~~

The password is created during installation.

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

## Removal

`uninstall.sh` removes the installation and its Docker database volume.

It requires an explicit `DELETE` confirmation.

## Advanced VPS setups

NAT/CGNAT VPS deployments and Cloudflare Tunnel are documented in `ADVANCED.md`.
