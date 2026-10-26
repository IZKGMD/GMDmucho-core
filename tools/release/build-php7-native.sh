#!/usr/bin/env bash
# Build the PHP7 shared runtime, compiled original domains and native owner UI.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OUTPUT="$ROOT/MuchoCore-PHP7-5v-native-preview.zip"
if [[ -n "${OUTPUT_PATH:-}" ]]; then
  OUTPUT="$OUTPUT_PATH"
fi
if [[ "$OUTPUT" != /* ]]; then
  OUTPUT="$ROOT/$OUTPUT"
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
for path in compat/php7/runtime.php compat/php7/download.php \
  compat/php7/upload.php compat/php7/auth.php compat/php7/news.php \
  compat/php7/schema.php compat/php7/users.php compat/php7/config.example.php \
  compat/php7/features.php compat/php7/features.sql \
  compat/php7/interactions.php compat/php7/cloudsave.php compat/php7/profile.php \
  compat/php7/admin.php compat/php7/admin-view.php compat/php7/bootstrap.php \
  compat/php7/social.php compat/php7/levels.php public/php7-admin.php \
  public/assets/php7-admin-base.css public/assets/php7-admin-host.js public/assets/muchocore-icon.png \
  public/php7.php public/php7-setup.php docs/PHP7_NATIVE_PHASE1.md; do
  if [[ ! -f "$ROOT/$path" ]]; then
    echo "Missing PHP7-native source: $path" >&2
    exit 1
  fi
done

mkdir -p "$STAGE/mc/compat" "$STAGE/mc/public" \
  "$STAGE/mc/config" "$STAGE/mc/docs"
cp -a "$ROOT/compat/php7" "$STAGE/mc/compat/"
rm -rf "$STAGE/mc/compat/php7/core/tests"
cp "$ROOT/public/php7.php" "$ROOT/public/php7-setup.php" "$STAGE/mc/public/"
cp "$ROOT/public/php7-admin.php" "$STAGE/mc/public/"
mkdir -p "$STAGE/mc/public/assets" "$STAGE/mc/public/recovery"
cp "$ROOT/public/recovery/recovery.css" "$STAGE/mc/public/recovery/"
cp "$ROOT/public/assets/muchocore-icon.png" "$ROOT/public/assets/muchocore-favicon-v4.png" "$STAGE/mc/public/assets/"
# Original VPS admin CSS, scripts and GD assets are copied byte-for-byte.
mkdir -p "$STAGE/mc/public/admin/assets"
for asset in admin-ui-v2.css admin-i18n.css admin-i18n.js admin-simple.css admin-simple.js admin-polish.css product-admin.css admin-workspace.css admin-motion.css admin-motion.js; do
  cp "$ROOT/public/admin/$asset" "$STAGE/mc/public/admin/"
done
cp -a "$ROOT/public/admin/assets/gd" "$STAGE/mc/public/admin/assets/"
cp "$ROOT/public/admin/assets/qrcode.min.js" "$STAGE/mc/public/admin/assets/"
cp "$ROOT/public/muchocore-theme.css" "$ROOT/public/muchocore-theme.js" "$STAGE/mc/public/"
for asset in creator-events.css product-ui.css php7-admin-base.css php7-admin-login.css php7-admin-product.js php7-admin-dashboard.js php7-admin-host.js php7-shared-patcher.js php7-passkeys.js; do
  cp "$ROOT/public/assets/$asset" "$STAGE/mc/public/assets/"
done
printf '%s\n' '<?php require __DIR__ . "/public/php7-admin.php";' > "$STAGE/mc/admin.php"
# Existing installations pin an immutable core snapshot. Ship the reviewed
# code-only update alongside the mirrors so the owner can advance that pointer.
"${PHP_BIN:-php}" "$ROOT/tools/release/build-php7-core-pack.php" "$STAGE/mc/config"
cp "$ROOT/docs/PHP7_NATIVE_PHASE1.md" "$STAGE/mc/docs/"
cp "$ROOT/docs/GD_2209_NATIVE_ENDPOINT_AUDIT.md" "$STAGE/mc/docs/"
cp "$ROOT/patchers/shared-hosting/README.md" \
   "$STAGE/mc/docs/5V_APACHE_ROUTES.md"

# Private compiled code and runtime data must never be served directly.
cat > "$STAGE/mc/.htaccess" <<'APACHE'
Options -Indexes
DirectoryIndex admin.php
RewriteEngine On
RewriteRule ^(?:config|compat|docs|storage|plugins)(?:/|$) - [F,L,NC]
RewriteRule ^public/php7-mode\.on$ - [F,L,NC]
RewriteRule ^(?:\.env|\.git)(?:/|$) - [F,L,NC]
RewriteRule ^admin/?$ public/php7-admin.php [QSA,L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ public/php7.php [QSA,L]
APACHE

cat > "$STAGE/mc/public/.htaccess" <<'APACHE'
Options -Indexes
DirectoryIndex php7-admin.php
RewriteEngine On
RewriteRule ^php7-mode\.on$ - [F,L,NC]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ php7.php [QSA,L]
APACHE

cat > "$STAGE/mc/config/.htaccess" <<'APACHE'
Options -Indexes
Require all denied
APACHE
cat > "$STAGE/mc/compat/.htaccess" <<'APACHE'
Options -Indexes
Require all denied
APACHE

cat > "$STAGE/5V_ROOT_REWRITE_RULES.txt" <<'APACHE'
# Add BELOW an existing RewriteEngine On and BEFORE catch-all rules
# in the main public WEBSITE ROOT .htaccess. Do not overwrite other rules.
RewriteRule ^gd-content/ mc/public/php7.php [QSA,L,NC]
RewriteRule ^api/api/accounts/lostusername\.php$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^(?:api|a)/(?:api/)?(?:profile|clans|muchocore)/ mc/public/php7.php [QSA,L,NC]
RewriteRule ^(?:a|database|accounts|api)/(?:accounts/)?[A-Za-z0-9_-]+\.php$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^(?:getAccountURL|getCustomContentURL|checkIfServerOnline)\.php$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^gd-content/news/news_version\.txt$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^gd-content/news/news\.dat$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^news/news_version\.txt$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^news/news\.dat$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^(?:gd-content/)?news/mcnews_[a-f0-9]{64}\.jpg$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^news/image\.php$ mc/public/php7.php [QSA,L,NC]
APACHE

cat > "$STAGE/START_HERE_SHARED.txt" <<'README'
MUCHOCORE SHARED — PHP 7.0+ / 5v.pl
===================================

This archive runs entirely on shared hosting without a VPS or Composer.
It includes the original core services compiled for PHP 7.0 and an English
administration interface. Coverage and acceptance status:
mc/docs/PHP7_NATIVE_PHASE1.md.

NEW INSTALLATION
----------------
1. Upload mc/ through FileZilla or your hosting file manager.
2. Copy mc/compat/php7/config.example.php to mc/config/php7-local.php.
   Enter the connection details for your EMPTY MySQL database. Keep this file
   private; it contains the database password.
3. Open https://YOUR-DOMAIN/mc/admin.php. Confirm the configured database
   password, choose the owner username/email/password and enter the HTTPS
   server origin without /mc. The feature checkbox enables game accounts,
   registration, writes, saves and social operations. The first player never
   automatically receives administrator access.
4. Add 5V_ROOT_REWRITE_RULES.txt below RewriteEngine On and before catch-all
   rules in the DOMAIN ROOT .htaccess. Preserve existing rules.
5. Open Server and complete the core migration. Check health, then test the
   actual game: registration/login, level upload/download, comments, friends
   and Cloud Save.

UPDATE AN EXISTING NATIVE INSTALLATION
-------------------------------------
1. Back up the current mc/ directory and database.
2. Upload the archive's mc/ files over the existing installation. Preserve
   config/php7-local.php, config/php7-news.json, public/php7-mode.on, private
   keys, backups and all existing data. This archive contains no live secrets.
3. Sign in with the existing owner account. If no owner was established yet,
   confirm the configured database password and your existing game account.
4. Use Update database if offered. The native schema upgrade preserves
   accounts, levels and saves. Do not rerun first-time setup on existing data.
5. Open Server, select Install uploaded build and wait for verification and
   installation. Use Complete migration if offered. Copying PHP files alone
   does not change an installation's pinned core snapshot.
6. Check the domain and feature switches in Settings and verify game routes.

SHARED FEATURES
---------------
* Original Styled and Plain admin, ratings, moderation, native administrator
  identities, roles, audit, private SQL backups and restore verification.
* Accounts, profiles, character settings, levels, search, scores, comments,
  votes, social privacy, friends, blocks, messages and local Cloud Save.
* Original application modules, clans, collections, quests, threads, following,
  plugin diagnostics, Control v4, security and monitoring pages.
* News Board with drafts, pinned posts, images and YouTube previews.
* Authenticator/recovery codes and device passkeys. Passkey enrollment/revocation
  requires the current password and enabled 2FA. Private keys stay on the device.
* Resumable EXE/APK patcher with PHP-only JAR/v1 and APK-v2 signing, without
  Java, shell commands or an external patching server.
* Bounded local queue work, optional signed HTTPS webhooks, resumable search
  rebuilding and CLI cron with verified daily backups.
* Cvolton account/profile/level/score preview and import with a verified target
  backup. Other detected datasets are reported explicitly.

HOST CONFIGURATION AND ACCEPTANCE
---------------------------------
For 8 MiB levels/saves, use post_max_size >= 26M and memory_limit >= 128M.
The hosting provider's HTTP and storage limits also apply. OpenSSL and
mbstring are required for passkeys; HTTPS cURL is required for webhooks.
Configure the cron command shown on Server in the provider's scheduler if
unattended work is wanted. The ZIP does not create a provider cron job.

Preserve config/php7-apk-signer.json to sign future APK updates with the same
installation identity. This private key is generated on the host and is not
included in the archive or SQL backups.

PHP 7.0 is obsolete. Automated protocol/database/signature tests do not certify
real-device game operation or provider download behavior. Third-party plugins
need their own runtime compatibility review. See the coverage document for
current limits. Keep the original game files and test patched output separately.

README

# Prohibit secrets in distributed ZIP; installer-generated keys/configs
# are never copied into this stage.
mkdir -p "$(dirname "$OUTPUT")"
rm -f "$OUTPUT"
(cd "$STAGE" && zip -qr "$OUTPUT" mc \
 "5V_ROOT_REWRITE_RULES.txt" "START_HERE_SHARED.txt")

unzip -t "$OUTPUT" >/dev/null
LIST="$STAGE/zip-list.txt"
unzip -Z1 "$OUTPUT" > "$LIST"
for item in mc/admin.php mc/public/php7-admin.php mc/public/assets/php7-admin-base.css mc/public/assets/php7-admin-host.js \
  mc/public/php7.php mc/public/php7-setup.php \
  mc/compat/php7/users.php mc/compat/php7/runtime.php \
  mc/compat/php7/news.php mc/compat/php7/schema.php \
  mc/compat/php7/features.sql mc/compat/php7/interactions.php \
  mc/compat/php7/cloudsave.php mc/compat/php7/profile.php \
  mc/.htaccess mc/public/.htaccess \
  5V_ROOT_REWRITE_RULES.txt START_HERE_SHARED.txt; do
  grep -Fxq "$item" "$LIST" || {
    echo "Package incomplete: $item" >&2
    exit 1
  }
done
for forbidden in mc/config/php7-local.php mc/config/php7-news.json \
  mc/config/php7-setup-key.txt mc/public/php7-mode.on \
  mc/vendor/autoload.php mc/src/Core/Application.php; do
  if grep -Fxq "$forbidden" "$LIST"; then
    echo "Forbidden private or PHP8-only package file: $forbidden" >&2
    exit 1
  fi
done
echo "PHP7_NATIVE_5V_ZIP_OK"
echo "OUTPUT=$OUTPUT"
