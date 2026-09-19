# MuchoCore Server Setup

## Easiest installation

Do not follow the manual PHP/Nginx steps unless you know why you need them.

Use:

```bash
curl -fsSL https://raw.githubusercontent.com/andrey888787/GMDmucho-core/main/install.sh -o install.sh
sudo bash install.sh
```

You need:

- a Linux VPS with root access;
- a domain pointing to that VPS;
- ports 80 and 443 open.

## Domain setup

At your DNS provider, create an A record:

```text
gdps.example.com -> YOUR_VPS_IP
```

Wait until the domain points to the VPS before running the installer.

## What the installer does

The installer automatically:

1. Installs Docker.
2. Downloads MuchoCore.
3. Starts MariaDB.
4. Starts PHP 8.3.
5. Starts Caddy and automatic HTTPS.
6. Generates database passwords.
7. Generates the cloud-save key.
8. Creates the database tables.
9. Creates the `admin` account.

## First login

Open:

```text
https://YOUR-DOMAIN/admin/
```

Username:

```text
admin
```

Use the password you entered during installation.

## Health check

Open:

```text
https://YOUR-DOMAIN/health
```

Expected response:

```text
1
```

## Connect the client

Once the server works, patch your Geometry Dash client.

Read [`CLIENT_SETUP.md`](CLIENT_SETUP.md).

## Update

```bash
sudo /opt/mucho-core/update.sh
```

## Logs

```bash
cd /opt/mucho-core
sudo docker compose logs -f
```

## Backup

Before a major update, make a database backup:

```bash
sudo /opt/mucho-core/bin/mucho-db-backup.sh
```

Also keep a secure backup of:

```text
/opt/mucho-core/config/cloudsave.key
```

## Remove

Warning: this removes the database volume.

```bash
sudo /opt/mucho-core/uninstall.sh
```
