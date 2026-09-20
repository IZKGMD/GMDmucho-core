# MuchoCore Server Setup

## Easiest installation

**VPS is the recommended way to run MuchoCore.**

You need:

- a Linux VPS with root access;
- a domain;
- ports 80 and 443 available.

### One command

```bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install.sh | sudo bash
```

The installer asks for the domain if you did not provide one.

For a completely pre-filled install:

```bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install.sh | sudo bash -s -- --domain gdps.example.com
```

On a new installation, the user creates the admin password. The installer asks for it twice and checks that both entries match.

### Domain setup

Create an A record:

```text
gdps.example.com -> YOUR_VPS_IP
```

The installer checks DNS and will warn you when the domain is not ready yet.

### What the installer does

One command performs the full setup:

1. Checks the VPS.
2. Installs Docker and required tools.
3. Downloads MuchoCore.
4. Generates database secrets.
5. Generates the Cloud Save key.
6. Starts MariaDB, PHP 8.3 and Caddy.
7. Applies database migrations.
8. Validates Docker Compose configuration.
9. Checks the local `/health` endpoint.
10. Checks the public `/health` endpoint.
11. Prints the GDPS and admin URLs.

### Running the installer again

The installer is designed to be safe to repeat.

- Existing secrets are preserved.
- Existing MuchoCore source is not wiped.
- A random unrelated directory is never deleted.
- Existing installations can be started again without re-creating credentials.

## First login

Open:

```text
https://YOUR-DOMAIN/admin/
```

Username:

```text
admin
```

Password:

Use the password you created during installation.

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
