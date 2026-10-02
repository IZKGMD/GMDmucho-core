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
~~~

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
