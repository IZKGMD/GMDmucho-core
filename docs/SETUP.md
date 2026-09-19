# MuchoCore deployment guide

This document expands the installation section from the root README.

## Recommended deployment layout

Use:

```text
/var/www/mucho-core
```

The current codebase contains operational helpers that use this absolute path.

## Required services

- Nginx
- PHP-FPM 8.3+
- PHP CLI 8.3+
- MySQL or MariaDB
- Composer 2+

## Environment

Create `/var/www/mucho-core/.env` from `.env.example`:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=muchocore
DB_USER=muchocore_user
DB_PASS=replace-me

MUCHO_ACCOUNT_URL=https://example.com
MUCHO_CUSTOM_CONTENT_URL=https://example.com/content
```

Keep this file out of Git.

## Cloud-save key

Generate the encryption key once per installation:

```bash
openssl rand -base64 32 > /var/www/mucho-core/config/cloudsave.key
chmod 600 /var/www/mucho-core/config/cloudsave.key
chown www-data:www-data /var/www/mucho-core/config/cloudsave.key
```

Losing this key makes previously encrypted cloud-save data unrecoverable. Back it up securely outside Git.

## Database

Create the database and a dedicated user:

```sql
CREATE DATABASE muchocore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'muchocore_user'@'127.0.0.1' IDENTIFIED BY 'replace-me';
GRANT ALL PRIVILEGES ON muchocore.* TO 'muchocore_user'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Then:

```bash
composer install --no-dev --optimize-autoloader
php bin/migrate.php status
php bin/migrate.php migrate
```

## Admin bootstrap

Create `/etc/muchocore-admin.php` with a password hash:

```php
<?php
return [
    'username' => 'admin',
    'password_hash' => 'REPLACE_WITH_PASSWORD_HASH',
];
```

Generate a hash using PHP:

```bash
php -r 'echo password_hash("YOUR_PASSWORD", PASSWORD_DEFAULT), PHP_EOL;'
```

The admin panel initializes its admin tables during the first request and imports the owner from this file.

## Nginx

Recommended virtual host:

```nginx
server {
    listen 80;
    server_name example.com;

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

For production, put HTTPS in front of the site and keep the database bound to localhost/private networking.

## Verification

```bash
curl -i https://example.com/health
MUCHO_TEST_BASE_URL=https://example.com ./tests/smoke.sh
MUCHO_TEST_BASE_URL=https://example.com ./tests/regression.sh
```

Expected compatibility health output:

```text
1
```

## Client URLs

Point the Geometry Dash client at the deployment domain. The compatibility entry points are in `public/database/` and the JSON API is in `public/api/v2/`.

Exact client patching steps vary by Geometry Dash version and by how your GDPS client is distributed.

## Maintenance

Run migrations after updating source code:

```bash
php bin/migrate.php migrate
```

Check health:

```bash
php bin/mucho-healthcheck.php
```

Run cleanup when scheduled:

```bash
php bin/mucho-monitor-cleanup.php
```

Run database backups after verifying the host has `mariadb-dump` or `mysqldump`:

```bash
bin/mucho-db-backup.sh
```
