#!/usr/bin/env bash
set -Eeuo pipefail

# MuchoProtect uses a *different* storage directory than MUCHO_CONTROL_DIR.
# The app's PHP-FPM workers (www-data) need to create and update rate-limit
# buckets here. If storage/control is root:root mode 700, strict rate limiting
# blocks every public API request with the legacy -1 response.
ROOT="${1:?Usage: bash docker/init-protect-storage.sh PROJECT_ROOT}"

[[ -d "$ROOT/storage" ]] || {
  echo '[MuchoCore] ERROR: storage/ directory is missing.' >&2
  exit 1
}

# Keep control flags owned by root. Allow the PHP group to traverse this
# directory so the application can read flags and reach the limiter buckets.
install -d -o root -g www-data -m 750 "$ROOT/storage/control"

# Keep mutable limiter/penalty state private to the PHP account. Existing
# deployments may contain root-owned buckets: fix those without touching
# maintenance and other control flags.
for name in rate-limit protect-penalties; do
  dir="$ROOT/storage/control/$name"
  install -d -o www-data -g www-data -m 700 "$dir"
  chown -R www-data:www-data "$dir"
done
