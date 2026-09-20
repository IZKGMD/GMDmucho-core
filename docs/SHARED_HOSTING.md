# Shared Hosting Setup

This guide is for a normal PHP hosting account.

You do **not** need Docker for this mode.

## What you need

Before starting, make sure your hosting account provides:

- PHP 8.3 or newer.
- MySQL or MariaDB.
- PDO MySQL.
- Composer access, or a way to run Composer in the hosting account.
- A domain or subdomain pointing to the site.

If your host only provides an older PHP version, stop here. The current MuchoCore release requires PHP 8.3+.

## Step 1 — Create the database

Open your hosting control panel and find:

```text
MySQL / MariaDB / Databases
```

Create a database and a database user.

Write down these values:

```text
Database host
Database port
Database name
Database username
Database password
```

Do not put the database password into GitHub.

## Step 2 — Upload MuchoCore

Download the repository and upload the complete project to your hosting account.

A simple layout is:

```text
your-site/
├── public/
├── src/
├── database/
├── vendor/
├── composer.json
└── ...
```

You can use the hosting file manager or SFTP.

### Document root

There are two supported layouts.

### Option A — document root points to `public/`

This is the cleanest setup.

Set your domain document root to:

```text
.../mucho-core/public
```

### Option B — document root points to the project folder

The repository includes a root `.htaccess` that internally uses `public/` as the website root.

This is useful when your hosting panel does not let you change the document root.

## Step 3 — Install Composer dependencies

The repository does not store the `vendor/` directory in Git.

From the hosting terminal, run:

```bash
cd /path/to/mucho-core
composer install --no-dev --optimize-autoloader
```

You should now have:

```text
vendor/autoload.php
```

If your hosting panel has a Composer button or Composer manager, that is also fine.

## Step 4 — Open the installer

Open:

```text
https://YOUR-DOMAIN/shared-install.php
```

The installer checks PHP and the required extensions first.

Do not continue while a red check is visible.

Then enter:

- your database host;
- database port;
- database name;
- database username;
- database password;
- your GDPS address;
- a password for the admin panel.

The admin username is always:

```text
admin
```

The installer creates the database tables and the admin account automatically.

## Step 5 — Check the server

Open:

```text
https://YOUR-DOMAIN/health
```

Expected result:

```text
1
```

Then open:

```text
https://YOUR-DOMAIN/admin/
```

Login:

```text
Username: admin
Password: the password you created during installation
```

## Delete the installer

The installer locks itself after a successful installation and tries to delete itself.

For safety, also check your hosting file manager and delete:

```text
public/shared-install.php
```

Do **not** delete:

```text
.env
storage/admin-bootstrap.php
config/cloudsave.key
```

## If the installer shows a red PHP check

The message tells you what is missing.

Typical examples:

### PHP version

Ask the hosting control panel to switch the site to PHP 8.3 or newer.

### PDO MySQL

Enable the MySQL database extension for PHP.

### Composer dependencies

Run:

```bash
composer install --no-dev --optimize-autoloader
```

### Not writable

The PHP process must be able to write to the MuchoCore project directory and `storage/`.

## Updating a shared-hosting installation

Shared hosting does not use the Docker `update.sh` command.

Use this safe workflow:

1. Back up the database.
2. Download the new MuchoCore release.
3. Replace the application source files.
4. Keep your `.env`.
5. Keep `storage/admin-bootstrap.php`.
6. Keep `config/cloudsave.key`.
7. Run:

```bash
composer install --no-dev --optimize-autoloader
```

8. Run the database migrations:

```bash
php bin/migrate.php migrate
```

If your hosting account does not allow terminal access, use the hosting provider's PHP/Composer manager or ask the provider how to run a PHP CLI command.

## Shared hosting limitations

The Shared Hosting mode intentionally does not depend on:

- Docker;
- Caddy;
- systemd;
- sudo;
- VPS-only maintenance commands.

Some admin maintenance actions shown in the panel are therefore available only on VPS deployments.

The Geometry Dash API, accounts, levels, social features, comments, scores and cloud-save endpoints use the same MuchoCore application code.

## Client setup

After the server works, patch the Geometry Dash client with:

```text
tools/client-patch.bat
```

The Windows patcher asks for **your** GDPS address.

It does not contain a fixed MuchoCore domain.

See [`CLIENT_SETUP.md`](CLIENT_SETUP.md).
