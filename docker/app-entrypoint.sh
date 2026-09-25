#!/usr/bin/env bash
set -Eeuo pipefail

ROOT=/var/www/mucho-core
cd "$ROOT"
DB_PASS="$(cat /run/secrets/db_password)"
ADMIN_PASS="$(cat /run/secrets/admin_password)"

install -d -m 750 -o root -g www-data /var/lib/muchocore
install -d -m 750 /var/lib/muchocore-control /var/lib/muchocore-backups
install -d -m 750 /var/www/mucho-core/storage/music-public
install -d -m 750 /var/www/mucho-core/storage/release-uploads
install -d -m 750 /var/www/mucho-core/releases/android
chown www-data:www-data /var/lib/muchocore-control /var/lib/muchocore-backups
chown www-data:www-data /var/www/mucho-core/storage /var/www/mucho-core/storage/music-public /var/www/mucho-core/storage/release-uploads /var/www/mucho-core/releases /var/www/mucho-core/releases/android

# Keep the Cloud Save key outside the bind-mounted source tree.
# Migrate an older project-local key exactly once without changing it, then
# remove the source-tree copy so PHP workers cannot replace the key in config/.
CLOUDSAVE_KEY=/var/lib/muchocore/cloudsave.key
if [[ -s config/cloudsave.key ]]; then
  install -m 640 -o root -g www-data config/cloudsave.key "$CLOUDSAVE_KEY"
elif [[ -s /run/secrets/cloudsave_key ]]; then
  install -m 640 -o root -g www-data /run/secrets/cloudsave_key "$CLOUDSAVE_KEY"
fi
if [[ ! -s "$CLOUDSAVE_KEY" ]]; then
  openssl rand -base64 32 > "$CLOUDSAVE_KEY"
  chown root:www-data "$CLOUDSAVE_KEY"
  chmod 640 "$CLOUDSAVE_KEY"
fi
if [[ -e config/cloudsave.key ]]; then
  rm -f config/cloudsave.key
fi

# Keep Docker Compose's project .env untouched.
# Runtime secrets live outside the bind-mounted project directory.
cat > /var/lib/muchocore/runtime.env <<EOFENV
DB_HOST=db
DB_PORT=3306
DB_NAME=${DB_NAME:-muchocore}
DB_USER=${DB_USER:-muchocore_user}
DB_PASS=$DB_PASS
EOFENV
chown root:www-data /var/lib/muchocore/runtime.env
chmod 640 /var/lib/muchocore/runtime.env

php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$ADMIN_PASS" > /var/lib/muchocore/admin-password.hash
chown root:www-data /var/lib/muchocore/admin-password.hash
chmod 640 /var/lib/muchocore/admin-password.hash

cat > /etc/muchocore-admin.php <<EOFPHP
<?php
return [
    'username' => '${ADMIN_USER:-admin}',
    'password_hash' => trim(file_get_contents('/var/lib/muchocore/admin-password.hash')),
];
EOFPHP
chown root:www-data /etc/muchocore-admin.php
chmod 640 /etc/muchocore-admin.php

if [[ ! -f vendor/autoload.php ]]; then
  composer install --no-dev --optimize-autoloader --no-interaction
fi

php bin/migrate.php migrate
exec "$@"
