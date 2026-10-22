#!/usr/bin/env bash
# Build a PHP-7.0-native GDPS subset, never mislabel the modern PHP8 core.
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
  public/php7.php public/php7-setup.php docs/PHP7_NATIVE_PHASE1.md; do
  if [[ ! -f "$ROOT/$path" ]]; then
    echo "Missing PHP7-native source: $path" >&2
    exit 1
  fi
done

mkdir -p "$STAGE/mc/compat" "$STAGE/mc/public" \
  "$STAGE/mc/config" "$STAGE/mc/docs"
cp -a "$ROOT/compat/php7" "$STAGE/mc/compat/"
cp "$ROOT/public/php7.php" "$ROOT/public/php7-setup.php" "$STAGE/mc/public/"
cp "$ROOT/docs/PHP7_NATIVE_PHASE1.md" "$STAGE/mc/docs/"
cp "$ROOT/patchers/shared-hosting/README.md" \
   "$STAGE/mc/docs/5V_APACHE_ROUTES.md"

# Port-specific root guard: no PHP8 classes, Composer, or unrelated routes.
cat > "$STAGE/mc/.htaccess" <<'APACHE'
Options -Indexes
DirectoryIndex public/php7.php
RewriteEngine On
RewriteRule ^(?:config|compat|docs)(?:/|$) - [F,L,NC]
RewriteRule ^public/php7-mode\.on$ - [F,L,NC]
RewriteRule ^(?:\.env|\.git)(?:/|$) - [F,L,NC]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ public/php7.php [QSA,L]
APACHE

cat > "$STAGE/mc/public/.htaccess" <<'APACHE'
Options -Indexes
DirectoryIndex php7.php
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
RewriteRule ^(?:a|database|accounts|api)/(?:accounts/)?[A-Za-z0-9_-]+\.php$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^(?:getAccountURL|getCustomContentURL|checkIfServerOnline)\.php$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^gd-content/news/news_version\.txt$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^gd-content/news/news\.dat$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^news/news_version\.txt$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^news/news\.dat$ mc/public/php7.php [QSA,L,NC]
APACHE

cat > "$STAGE/НАЧНИ_ЗДЕСЬ_5V.txt" <<'README'
MuchoCore PHP 7.0 native preview for 5v.pl (experimental)
===========================================================

THIS ZIP IS NOT THE FULL PHP 8.2+ MUCHOCORE SERVER.
It contains the independently ported PHP 7.0-compatible subset.
Do not put production player account credentials on obsolete PHP 7.

What is included:
* Local MySQL public level list/search and public level downloads.
* Public GD 2.209 user profile, search and top/creator leaderboards.
* GD 2.209 text-only Native News (requires opt-in).
* Public server checks and account URL discovery.
* Native level/profile comments, comment history and authenticated likes.
* Authenticated score/appearance updates and logged-in public browsing.
* Local database cloud saves up to 8 MiB (separate enable_cloudsave switch).
* Experimental accounts and level uploads are present but DISABLED by
  default and NOT certified for live/public use.
* Strict opt-in native installer for an EMPTY, THROWAWAY test MySQL DB.
* No admin panel, full modern migrations, clans or native
  2.209 private-social features. Those still need a supported PHP runtime.

INSTALLATION FOR AN ISOLATED TEST GDPS ONLY
------------------------------------------
1. Preserve your current working /mc directory and database first.
2. Upload the directory "mc/" from this ZIP to your domain via FTP.
3. In the WEBSITE ROOT .htaccess, append the contents of
   5V_ROOT_REWRITE_RULES.txt just after RewriteEngine On and before any
   catch-all rule. Do not overwrite other existing rules.
4. If you have already installed the experimental native adapter, keep
   existing PRIVATE /mc/config/php7-local.php and data as-is; never overwrite.
   Its "site_url" setting must be the HTTPS base origin of YOUR test host.
   Import mc/compat/php7/features.sql using phpMyAdmin into that SAME native
   database. This adds three new tables without altering existing player data;
   it does not import or migrate PHP8/Cvolton comments or cloud saves.
   No installer reset or database cleanup is required for this upgrade.
   Experimental writes still require enable_accounts and enable_writes=true;
   registration needs enable_registration=true, saves need enable_cloudsave=true.
   For saves set the host's post_max_size to at least 26M and memory_limit
   to at least 128M. The cloud-save limit itself remains 8 MiB.
5. For a new EMPTY and DISPOSABLE TEST database only, read
   mc/docs/PHP7_NATIVE_PHASE1.md before starting php7-setup.php.
   It requires a locally-created setup key and secure HTTPS. Never run this
   installer against your live database. Remove php7-setup.php afterwards.
6. After configuration, enable php7 routing by creating the empty
   /mc/public/php7-mode.on file (NOT included in this archive).
7. For News in a private test, copy the example JSON into the private
   /mc/config/php7-news.json and explicitly configure enable_native_news.
8. Confirm an actual native GD 2.209 client works; local tests only verify
   code and wire protocol, not 5v.pl provider behavior or complete support.

Never commit or share php7-local.php, mysql passwords, auth peppers,
php7-setup-key.txt, user cloud saves or any other production secrets.

The COMPLETE PHP 8.2+ MuchoCore is packaged separately as
MuchoCore-full-PHP82-shared-hosting.zip and cannot run on 5v.pl's PHP 7.0.
README

# Prohibit secrets in distributed ZIP; installer-generated keys/configs
# are never copied into this stage.
mkdir -p "$(dirname "$OUTPUT")"
rm -f "$OUTPUT"
(cd "$STAGE" && zip -qr "$OUTPUT" mc \
 "5V_ROOT_REWRITE_RULES.txt" "НАЧНИ_ЗДЕСЬ_5V.txt")

unzip -t "$OUTPUT" >/dev/null
LIST="$STAGE/zip-list.txt"
unzip -Z1 "$OUTPUT" > "$LIST"
for item in mc/public/php7.php mc/public/php7-setup.php \
  mc/compat/php7/users.php mc/compat/php7/runtime.php \
  mc/compat/php7/news.php mc/compat/php7/schema.php \
  mc/compat/php7/features.sql mc/compat/php7/interactions.php \
  mc/compat/php7/cloudsave.php mc/compat/php7/profile.php \
  mc/.htaccess mc/public/.htaccess \
  5V_ROOT_REWRITE_RULES.txt; do
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
