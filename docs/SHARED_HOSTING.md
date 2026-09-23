# PHP Shared Hosting / Beget

This guide is for regular PHP hosting without Docker.

## What you need

- PHP 8.3 or newer;
- MySQL or MariaDB;
- PDO MySQL;
- FTP access or a hosting file manager;
- a domain or subdomain.

## Recommended method: FTP package

MuchoCore provides a ready-to-upload package with Composer dependencies already installed.

You do **not** need SSH or Composer on the hosting server when using this package.

The package is built automatically by GitHub Actions as:

```text
mucho-core-ftp-php83-v1.1.0.zip
```

It contains the production PHP code, migrations, public files, and `vendor/`.

## 1. Create the database

In the Beget control panel, open the MySQL / MariaDB / Databases section.

Create an empty database.

You will need:

```text
database host
database port
database name
database username
database password
```

Do not publish the database password.

The web installer creates the MuchoCore tables automatically. It does not create the hosting provider's database itself.

## 2. Upload MuchoCore by FTP

Download the **FTP PHP 8.3 package** and extract it on your computer.

Upload the contents of the `ftp-package/` directory to your hosting account.

Keep the folder structure intact:

```text
.htaccess
composer.json
composer.lock
VERSION
public/
src/
database/
config/
vendor/
```

**Do not copy only the contents of `public/` into the project root.**

### Best setup

When your hosting panel lets you choose the domain document root, point it to:

```text
.../mucho-core/public
```

### When document root cannot be changed

You can leave the domain pointing to the project root.

The root `.htaccess` redirects requests into `public/`.

This also preserves the legacy `/database/...` paths used by Geometry Dash clients.

## 3. Open the installer

Open:

```text
https://YOUR-DOMAIN/shared-install.php
```

The installer checks:

- PHP version;
- required PHP extensions;
- MuchoCore files;
- Composer dependencies;
- writable directories.

When every check is green, enter your database details and choose the admin password.

The default admin username is:

```text
admin
```

The installer then:

1. writes `.env`;
2. generates the Cloud Save key when needed;
3. generates the download HMAC key;
4. runs every database migration;
5. creates the `admin` account;
6. locks the installer.

## 4. Check the server

Open:

```text
https://YOUR-DOMAIN/health
```

Expected response:

```text
1
```

Then open:

```text
https://YOUR-DOMAIN/admin/
```

## 5. Connect Geometry Dash

After `/health` works, continue with:

```text
docs/CLIENT_SETUP.md
```

For Windows, use:

```text
tools/client-patch.bat
```

## After installation

The installer attempts to remove:

```text
public/shared-install.php
```

If it is still present, delete it through FTP or the hosting file manager.

Do **not** delete:

```text
.env
storage/admin-bootstrap.php
config/cloudsave.key
```

Do not publish those files.

## Updating

Back up the database first.

Then replace the application files while keeping the existing:

```text
.env
config/cloudsave.key
storage/
```

With the FTP package, `vendor/` is already included, so Composer is not required.

## If something does not work

Check in this order:

1. `/health`
2. the hosting PHP error log;
3. the database values in `.env`;
4. PHP 8.3+ and PDO MySQL;
5. that `vendor/autoload.php` exists.

For a normal Beget installation, you should not need Docker, root access, or SSH.

## Advanced option: Composer

When your hosting provides a terminal and Composer, you can install dependencies yourself:

```bash
composer install --no-dev --optimize-autoloader
```

Then open `/shared-install.php` as usual.
