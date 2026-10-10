#!/usr/bin/env bash
set -Eeuo pipefail

ROOT=/var/www/mucho-core
cd "$ROOT"
DB_PASSWORD_FILE="${MUCHO_DB_PASSWORD_FILE:-/run/secrets/db_password}"
ADMIN_PASSWORD_FILE="${MUCHO_ADMIN_PASSWORD_FILE:-/run/secrets/admin_password}"
ADMIN_BOOTSTRAP_ENABLED=1
DB_PASS="$(cat "$DB_PASSWORD_FILE")"
if [[ "${MUCHO_SKIP_ADMIN_BOOTSTRAP:-0}" == "1" ]]; then
  ADMIN_BOOTSTRAP_ENABLED=0
else
  [[ -r "$ADMIN_PASSWORD_FILE" ]] || {
    echo "[MuchoCore] ERROR: admin password secret is required for admin-bearing services." >&2
    exit 1
  }
  ADMIN_PASS="$(cat "$ADMIN_PASSWORD_FILE")"
fi

install -d -m 750 -o root -g www-data /var/lib/muchocore
install -d -m 750 -o root -g www-data /var/lib/muchocore/android-signer
ANDROID_SIGNER_DIR=/var/lib/muchocore/android-signer
SIGNER_KEY="$ANDROID_SIGNER_DIR/muchocore-android.key.pk8"
LEGACY_SIGNER_KEY="$ANDROID_SIGNER_DIR/muchocore-android.key.pem"
SIGNER_CERT="$ANDROID_SIGNER_DIR/muchocore-android.cert.pem"

if [[ ! -s "$SIGNER_KEY" ]]; then
  if [[ -s "$LEGACY_SIGNER_KEY" ]]; then
    if [[ ! -s "$SIGNER_CERT" ]]; then
      openssl req -new -x509 -sha256 \
        -key "$LEGACY_SIGNER_KEY" \
        -out "$SIGNER_CERT" \
        -days 10000 \
        -subj "/CN=MuchoCore Android/O=MuchoCore/C=US"
    fi
    openssl pkcs8 -topk8 -nocrypt \
      -inform PEM -outform DER \
      -in "$LEGACY_SIGNER_KEY" \
      -out "$SIGNER_KEY"
    rm -f "$LEGACY_SIGNER_KEY"
  else
    PEM_KEY="$SIGNER_KEY.pem"
    openssl genpkey \
      -algorithm RSA \
      -pkeyopt rsa_keygen_bits:2048 \
      -out "$PEM_KEY"
    openssl req -new -x509 -sha256 \
      -key "$PEM_KEY" \
      -out "$SIGNER_CERT" \
      -days 10000 \
      -subj "/CN=MuchoCore Android/O=MuchoCore/C=US"
    openssl pkcs8 -topk8 -nocrypt \
      -inform PEM -outform DER \
      -in "$PEM_KEY" \
      -out "$SIGNER_KEY"
    rm -f "$PEM_KEY"
  fi
elif [[ ! -s "$SIGNER_CERT" ]]; then
  PEM_KEY="$SIGNER_KEY.pem"
  openssl pkcs8 -inform DER -nocrypt \
    -in "$SIGNER_KEY" \
    -out "$PEM_KEY"
  openssl req -new -x509 -sha256 \
    -key "$PEM_KEY" \
    -out "$SIGNER_CERT" \
    -days 10000 \
    -subj "/CN=MuchoCore Android/O=MuchoCore/C=US"
  rm -f "$PEM_KEY"
fi

chown root:www-data "$SIGNER_KEY" "$SIGNER_CERT"
chmod 640 "$SIGNER_KEY"
chmod 644 "$SIGNER_CERT"

openssl pkcs8 -inform DER -nocrypt -in "$SIGNER_KEY" -out /dev/null

install -d -m 750 /var/lib/muchocore-control /var/lib/muchocore-backups
install -d -m 750 /var/www/mucho-core/storage/music-public
install -d -m 750 /var/www/mucho-core/storage/release-uploads
install -d -m 750 /var/www/mucho-core/releases/android
chown www-data:www-data /var/lib/muchocore-control /var/lib/muchocore-backups

# MuchoProtect's strict limiter needs writable state *inside* storage/control.
# MUCHO_CONTROL_DIR alone does not cover the file-backed rate limiter.
bash "$ROOT/docker/init-protect-storage.sh" "$ROOT"
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
DB_HOST=${DB_HOST:-db}
DB_PORT=${DB_PORT:-3306}
DB_NAME=${DB_NAME:-muchocore}
DB_USER=${DB_USER:-muchocore_user}
DB_PASS=$DB_PASS
EOFENV
chown root:www-data /var/lib/muchocore/runtime.env
chmod 640 /var/lib/muchocore/runtime.env

if [[ "$ADMIN_BOOTSTRAP_ENABLED" == "1" ]]; then
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
fi

if [[ ! -f vendor/autoload.php ]]; then
  composer install --no-dev --optimize-autoloader --no-interaction
else
  # The source tree is bind-mounted into the container. Refresh Composer's
  # project autoload map after updates so newly added MuchoCore classes are
  # available even when vendor/ already exists from an earlier image/build.
  composer dump-autoload --no-dev --optimize --no-interaction
fi

php bin/migrate.php migrate
exec "$@"
