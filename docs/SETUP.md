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

From the repository root:

~~~bash
sudo ./install
~~~

The installer opens a version-selection menu:

~~~text
1) All supported versions (GD 1.9 - 2.2)
2) GD 1.9 only
3) GD 2.0 only
4) GD 2.1 only
5) GD 2.2 only
6) Custom profile
~~~

The selected compatibility profile is stored in `.env` and enforced by the runtime without duplicating the server core.

For unattended deployment, set `MUCHO_GD_VERSIONS` first:

~~~bash
export MUCHO_GD_VERSIONS=19,22
sudo -E bash install.sh
~~~

The installer prepares:

- MariaDB;
- PHP 8.3;
- Caddy;
- the MuchoCore database;
- cloud save keys;
- the administrator account;
- the selected Geometry Dash compatibility profile.

## 3. Verify the server

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

The password is created during installation.

## 4. Connect Geometry Dash

After `/health` works, follow `CLIENT_SETUP.md` for the client patching/connection flow.

## Updating

Use:

~~~bash
sudo /opt/mucho-core/update.sh
~~~

Updates preserve the selected compatibility profile and Cloudflare Tunnel deployment mode.

### Release-based automatic updates

MuchoCore checks GitHub Releases on the VPS. A new **published stable release** triggers the automatic updater. Ordinary commits on `main`, drafts and prereleases are ignored.

The updater then runs the normal `update.sh` deployment path, which fetches the exact release tag and never deploys directly from `main`.

For manual deployment/testing, the same path remains available:

~~~bash
sudo /opt/mucho-core/update.sh
~~~

## Logs

~~~bash
cd /opt/mucho-core
sudo docker compose logs --tail=100
~~~

For live logs:

~~~bash
sudo docker compose logs -f
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
