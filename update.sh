#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"
[[ $EUID -eq 0 ]] || { echo 'Run: sudo ./update.sh' >&2; exit 1; }

echo '[MuchoCore] Updating source code...'
git fetch --depth=1 origin main
git reset --hard origin/main

echo '[MuchoCore] Rebuilding containers...'
docker compose up -d --build --remove-orphans

echo '[MuchoCore] Updating PHP dependencies...'
docker compose exec -T app composer install --no-dev --optimize-autoloader --no-interaction

echo '[MuchoCore] Applying database migrations...'
docker compose exec -T app php bin/migrate.php migrate

echo '[MuchoCore] Checking service status...'
docker compose ps
