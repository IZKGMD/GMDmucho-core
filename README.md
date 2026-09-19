# MuchoCore

`PHP 8.3+` · `MySQL/MariaDB` · `GDPS` · `Geometry Dash`

**Modern PHP 8.3+ backend core for Geometry Dash private servers (GDPS).**

MuchoCore is built for people who want a GDPS backend that is easier to extend, configure, test and operate than a collection of endpoint scripts. The core keeps Geometry Dash-compatible request/response handling while adding a structured application layer, migrations, a JSON API, administration tooling, rate limiting and operational checks.

> **Positioning:** MuchoCore is not a replacement for every use case. Cvolton/GMDprivateServer is a mature and widely adopted project. MuchoCore is aimed at new GDPS deployments that prefer a modern PHP application structure and an explicit operational workflow.

## Why choose MuchoCore?

If your goal is to launch a new GDPS and keep developing it rather than only copying an existing endpoint set, MuchoCore gives you a cleaner starting point:

- **Modern runtime:** PHP **8.3+** with Composer and PSR-4 autoloading.
- **Configuration outside source code:** database credentials live in `.env`; cloud-save secrets are generated separately.
- **Database migrations:** schema changes are tracked as ordered migration files instead of requiring one giant SQL import to be the deployment mechanism.
- **Structured architecture:** HTTP, routing, accounts, levels, social features, moderation, scores and cloud saves live in separate services/repositories/controllers.
- **Geometry Dash compatibility:** the kernel exposes the expected account, level, score, comments, social, song and cloud-save endpoints, including compatibility aliases.
- **Encrypted cloud saves:** the current implementation uses AES-256-GCM and keeps revision history instead of treating a save as a single opaque blob.
- **JSON API v2:** the project includes a separate API surface for health, profiles, dashboard data, music, client configuration and presence.
- **Operational tooling:** health checks, database migration commands, backups, monitoring cleanup, role tooling and client/release helpers are included.
- **Tests included:** smoke, regression and load scripts are shipped with the repository.
- **Admin panel:** the repository contains a dedicated admin UI with role separation, audit logging and TOTP/2FA support.
- **Safer repository layout:** secrets, runtime logs, database dumps, release archives and temporary debug artifacts are intentionally kept out of source control.

### MuchoCore vs Cvolton/GMDprivateServer

Cvolton/GMDprivateServer remains one of the best-known GDPS emulator projects, with a large existing community and a long history of development. Its official README documents support for Geometry Dash 1.0–2.2, PHP 5.5+ (tested through PHP 8.1.2), a `database.sql` import workflow and manual client URL changes. citehttps://github.com/Cvolton/GMDprivateServer

| Area | MuchoCore | Cvolton/GMDprivateServer |
|---|---|---|
| Runtime | PHP 8.3+ | PHP 5.5+; README says tested up to 8.1.2 |
| Database setup | Ordered PHP migrations | `database.sql` import |
| Configuration | `.env` + separate secret files | Repository config files + client URL edits |
| Code organization | PSR-4 classes, services, repositories, controllers | Endpoint-oriented PHP layout |
| API | Geometry Dash-compatible routes + JSON API v2 | Geometry Dash emulator endpoints |
| Cloud saves | AES-256-GCM + revision history | Cloud-save encryption based on the project's documented crypto dependency |
| Operations | Health checks, rate limiting, backups, monitoring helpers | Update files and established server tooling |
| Testing | Smoke + regression + load scripts included | Existing project test/support workflow varies by branch/setup |
| Admin | Dedicated admin UI, roles, audit log, TOTP/2FA | Dashboard/admin functionality is provided in the project tree |

The practical choice is simple: **choose MuchoCore when you want the backend itself to be a modern application you can continue engineering.** Choose the Cvolton project when its established compatibility, ecosystem, existing GDPS guides or historical codebase are the features you specifically need.

## Features

### Geometry Dash backend

- Account registration and login.
- Profiles and account settings.
- Level upload, download, delete, metadata and descriptions.
- Level and platformer scores.
- Comments, likes, ratings, reports and moderation.
- Friends, blocks and private messages.
- Song information endpoints.
- Rewards and challenges.
- Legacy URL normalization and compatibility aliases.

### Core services

```text
public/
  index.php              Main HTTP entry point
  database/              Geometry Dash-compatible endpoint wrappers
  api/v2/                JSON API v2 endpoints
  admin/                 Web administration panel

src/
  Core/                  Application bootstrap and core services
  Http/                  Request/response abstractions
  Routing/               Routes and compatibility aliases
  Database/              PDO connection and migrations
  Account/               Authentication and accounts
  User/                  Profiles and user operations
  Level/                 Level transfer and metadata
  Score/                 Level/platformer score handling
  Interaction/           Comments, likes, rewards and ratings
  Social/                Friends, blocks and messages
  CloudSave/             Encrypted cloud-save storage
  Protocol/              Geometry Dash response encoders
  Moderation/            Reports and moderation actions
  Music/                 Song handling
  V71/                   v7.1 compatibility helpers

database/migrations/     Ordered schema migrations
bin/                     CLI/admin/maintenance tools
tests/                   Smoke, regression and load tests
docs/                    API and deployment documentation
```

## Requirements

- Linux server (recommended for the included operational scripts).
- PHP **8.3+**.
- Composer 2+.
- MySQL or MariaDB with PDO MySQL.
- OpenSSL.
- Nginx + PHP-FPM (recommended) or another PHP-capable web server.
- `curl` for the shell test suite.

> **Important:** the current operational scripts use `/var/www/mucho-core` in several places. The easiest supported layout is therefore exactly `/var/www/mucho-core`. Moving the project elsewhere requires updating those absolute paths or creating a matching symlink.

# Installation and configuration

The following procedure is the intended clean deployment path.

## 1. Prepare the server

Example for a Debian/Ubuntu-style host:

```bash
sudo apt update
sudo apt install -y nginx mariadb-server curl unzip git \
  php8.3 php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-opcache
```

Install Composer 2 if it is not already present, then verify:

```bash
php -v
composer --version
mysql --version
nginx -v
```

## 2. Create the database

Open MariaDB/MySQL:

```bash
sudo mariadb
```

Create a dedicated database and user:

```sql
CREATE DATABASE muchocore
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'muchocore_user'@'127.0.0.1'
  IDENTIFIED BY 'CHANGE_THIS_TO_A_LONG_RANDOM_PASSWORD';

GRANT ALL PRIVILEGES ON muchocore.*
  TO 'muchocore_user'@'127.0.0.1';

FLUSH PRIVILEGES;
EXIT;
```

Use a unique database password and never put that password into Git.

## 3. Put MuchoCore at the expected path

```bash
sudo mkdir -p /var/www
cd /var/www
sudo git clone <YOUR_GITHUB_REPOSITORY_URL> mucho-core
sudo chown -R www-data:www-data /var/www/mucho-core
cd /var/www/mucho-core
```

Or upload/extract the repository there.

## 4. Install PHP dependencies

```bash
cd /var/www/mucho-core
composer install --no-dev --optimize-autoloader
```

For development, use:

```bash
composer install
```

## 5. Create `.env`

```bash
cp .env.example .env
chmod 600 .env
nano .env
```

Minimum configuration:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=muchocore
DB_USER=muchocore_user
DB_PASS=CHANGE_THIS_TO_A_LONG_RANDOM_PASSWORD
```

Optional endpoint overrides:

```dotenv
MUCHO_ACCOUNT_URL=https://your-domain.example
MUCHO_CUSTOM_CONTENT_URL=https://your-content.example
```

`MUCHO_ACCOUNT_URL` and `MUCHO_CUSTOM_CONTENT_URL` are read by the v7.1 compatibility configuration in `config/mucho_v71.php`.

## 6. Generate the cloud-save encryption key

Generate a fresh 32-byte key for this installation:

```bash
openssl rand -base64 32 > /var/www/mucho-core/config/cloudsave.key
chmod 600 /var/www/mucho-core/config/cloudsave.key
sudo chown www-data:www-data /var/www/mucho-core/config/cloudsave.key
```

Do **not** commit this file. It is intentionally ignored by Git.

## 7. Run database migrations

Check migration state first:

```bash
php bin/migrate.php status
```

Apply everything:

```bash
php bin/migrate.php migrate
```

The migration set creates the core accounts, profiles, levels, songs, interactions, moderation, protocol and kernel tables and then applies later hardening migrations.

You can use the compatibility wrapper as well:

```bash
php bin/mucho-migrate.php
```

## 8. Configure the admin bootstrap

The web admin panel creates its `admin_users` and `admin_audit_logs` tables automatically on first load. The first owner account is bootstrapped from:

```text
/etc/muchocore-admin.php
```

Create a password hash:

```bash
php -r 'echo password_hash("REPLACE_WITH_A_STRONG_PASSWORD", PASSWORD_DEFAULT), PHP_EOL;'
```

Create the configuration file as root:

```bash
sudo nano /etc/muchocore-admin.php
sudo chmod 600 /etc/muchocore-admin.php
```

Use:

```php
<?php

return [
    'username' => 'admin',
    'password_hash' => 'PASTE_THE_PASSWORD_HASH_HERE',
];
```

The first request to `/admin/` creates the owner account from this configuration. After the first successful login, enable TOTP/2FA from the admin interface.

## 9. Configure Nginx

Create `/etc/nginx/sites-available/muchocore`:

```nginx
server {
    listen 80;
    server_name your-domain.example;

    root /var/www/mucho-core/public;
    index index.php index.html;

    client_max_body_size 25M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

Enable it:

```bash
sudo ln -s /etc/nginx/sites-available/muchocore /etc/nginx/sites-enabled/muchocore
sudo nginx -t
sudo systemctl reload nginx
```

Then put TLS in front of it. For a public deployment, use HTTPS and set secure session cookies as already expected by the admin panel.

## 10. Test the kernel

First check the lightweight health endpoint:

```bash
curl -i https://your-domain.example/health
```

The compatibility health response is expected to be:

```text
1
```

Run the repository smoke tests against the deployed instance:

```bash
MUCHO_TEST_BASE_URL=https://your-domain.example ./tests/smoke.sh
```

Run the regression suite:

```bash
MUCHO_TEST_BASE_URL=https://your-domain.example ./tests/regression.sh
```

Load testing:

```bash
MUCHO_TEST_BASE_URL=https://your-domain.example ./tests/load.sh
```

## 11. Configure the Geometry Dash client

Your GDPS client still needs its server endpoints redirected to your domain. MuchoCore exposes the Geometry Dash-compatible endpoints under `public/database/` and related wrappers, while the JSON API lives under `public/api/v2/`.

For each client build, point the account/content/server URLs at your deployment. The exact patching method depends on the GD version and client you are distributing.

A typical final layout is:

```text
https://your-domain.example/
https://your-domain.example/database/...
https://your-domain.example/api/v2/...
https://your-domain.example/admin/
```

## 12. Backups and maintenance

The repository includes backup and operational helpers under `bin/`.

Database backup:

```bash
/var/www/mucho-core/bin/mucho-db-backup.sh
```

Health check:

```bash
php /var/www/mucho-core/bin/mucho-healthcheck.php
```

Monitoring cleanup:

```bash
php /var/www/mucho-core/bin/mucho-monitor-cleanup.php
```

Before enabling scheduled jobs, verify their absolute paths and the permissions of the backup/log directories on your server.

## 13. Common configuration checklist

Before opening the GDPS to users, verify:

```text
[ ] PHP 8.3+ is running
[ ] Composer dependencies are installed
[ ] .env exists and is chmod 600
[ ] DB credentials work
[ ] migrations are applied
[ ] config/cloudsave.key exists and is chmod 600
[ ] /var/www/mucho-core is readable by PHP-FPM
[ ] Nginx root points to /var/www/mucho-core/public
[ ] HTTPS is enabled
[ ] /admin/ owner account is configured
[ ] TOTP/2FA is enabled for administrators
[ ] /health returns 1
[ ] smoke/regression tests pass
[ ] database backups work
[ ] Geometry Dash client URLs point to your server
```

## Security notes

Do not commit:

- `.env`
- `config/cloudsave.key`
- database dumps
- runtime logs
- release ZIPs
- local backups
- temporary debug files

If a database password, application secret or cloud-save key has ever existed in an old exported server archive, rotate it before publishing or deploying the repository.

## Deployment documentation

A full server setup walkthrough is available in [`docs/SETUP.md`](docs/SETUP.md).

## API documentation

OpenAPI documentation is available at [`docs/openapi.yaml`](docs/openapi.yaml).

## License

This repository is licensed under the MIT License. See [`LICENSE`](LICENSE) for the full text.

## Credits / inspiration

MuchoCore targets the same broad problem space as established Geometry Dash private-server projects such as [Cvolton/GMDprivateServer](https://github.com/Cvolton/GMDprivateServer). Compatibility knowledge and community history in the GDPS ecosystem are valuable; this project focuses on a newer internal application structure and deployment workflow.


## Quick deployment

For a clean Debian/Ubuntu VPS, run:

```bash
curl -fsSL https://raw.githubusercontent.com/andrey888787/GMDmucho-core/main/install.sh -o install.sh
sudo bash install.sh
```

The installer asks for the domain and admin password, installs Docker, starts MariaDB + PHP-FPM + Caddy, creates the application environment and cloud-save key, installs Composer dependencies, and runs database migrations.

After installation:

- `https://YOUR-DOMAIN/` — GDPS endpoint;
- `https://YOUR-DOMAIN/admin/` — admin panel;
- `https://YOUR-DOMAIN/health` — health check, expected response: `1`.

Update the installation with:

```bash
sudo /opt/mucho-core/update.sh
```

Remove the installation and its database volume with:

```bash
sudo /opt/mucho-core/uninstall.sh
```

For automatic HTTPS, the domain must already point to the VPS and TCP ports 80 and 443 must be reachable from the Internet.


## Client setup

Server installation and client installation are separate steps. After the server is online, patch your own Geometry Dash client to use the MuchoCore database URL:

```text
https://YOUR-DOMAIN/database
```

Read [`docs/CLIENT_SETUP.md`](docs/CLIENT_SETUP.md) for the complete Windows, macOS, Android and iOS workflow. The repository also includes [`tools/client-patch.py`](tools/client-patch.py) to perform the known 2.2 binary URL replacements with length checks.
