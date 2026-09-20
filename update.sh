#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"
[[ $EUID -eq 0 ]] || { echo 'Run: sudo ./update.sh' >&2; exit 1; }

echo '[MuchoCore] Updating source code...'
git fetch --depth=1 origin main

if git show origin/main:docker-compose.yml >/dev/null 2>&1; then
    git reset --hard origin/main
else
    if git ls-remote --exit-code origin refs/heads/feat/easy-deploy >/dev/null 2>&1; then
        echo '[MuchoCore] Main does not contain the deployment files yet; using feat/easy-deploy.'
        git fetch --depth=1 origin feat/easy-deploy
        git reset --hard FETCH_HEAD
    else
        echo '[MuchoCore] ERROR: the deployment files are not available on main or feat/easy-deploy.' >&2
        exit 1
    fi
fi

echo '[MuchoCore] Rebuilding containers...'
docker compose up -d --build --remove-orphans

echo '[MuchoCore] Updating PHP dependencies...'
docker compose exec -T app composer install --no-dev --optimize-autoloader --no-interaction

echo '[MuchoCore] Applying database migrations...'
docker compose exec -T app php bin/migrate.php migrate

echo '[MuchoCore] Checking service status...'
docker compose ps
