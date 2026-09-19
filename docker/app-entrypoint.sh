#!/usr/bin/env bash
set -Eeuo pipefail

ROOT=/var/www/mucho-core
cd "$ROOT"
DB_PASS="$(cat /run/secrets/db_password)"
ADMIN_PASS="$(cat /run/secrets/admin_password)"

cat > .env <<EOFENV
DB_HOST=db
DB_PORT=3306
DB_NAME=${DB_NAME:-muchocore}
DB_USER=${DB_USER:-muchocore_user}
DB_PASS=$DB_PASS
MUCHO_ACCOUNT_URL=${MUCHO_ACCOUNT_URL:-}
MUCHO_CUSTOM_CONTENT_URL=${MUCHO_CUSTOM_CONTENT_URL:-https://geometrydashfiles.b-cdn.net}
EOFENV
chmod 600 .env

install -d -o www-data -g www-data config /var/lib/muchocore
if [[ ! -s config/cloudsave.key ]]; then
  openssl rand -base64 32 > config/cloudsave.key
fi
chown www-data:www-data config/cloudsave.key
chmod 600 config/cloudsave.key

php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$ADMIN_PASS" > /var/lib/muchocore/admin-password.hash
chmod 600 /var/lib/muchocore/admin-password.hash

cat > /etc/muchocore-admin.php <<EOFPHP
<?php
return [
    'username' => '${ADMIN_USER:-admin}',
    'password_hash' => trim(file_get_contents('/var/lib/muchocore/admin-password.hash')),
];
EOFPHP
chmod 600 /etc/muchocore-admin.php

if [[ ! -f vendor/autoload.php ]]; then
  composer install --no-dev --optimize-autoloader --no-interaction
fi

php bin/migrate.php migrate
exec "$@"
