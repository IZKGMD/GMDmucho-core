#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"
[[ $EUID -eq 0 ]] || { echo 'Run: sudo ./update.sh' >&2; exit 1; }

# Older MuchoCore versions accidentally overwrote the project .env from inside
# the app container. Recover the domain from the existing Caddy container so
# the first update can repair that installation automatically.
if ! grep -q '^DOMAIN=' "$ROOT/.env" 2>/dev/null; then
    CADDY_ID="$(docker ps -a --filter 'label=com.docker.compose.service=caddy' --format '{{.ID}}' | head -n1 || true)"
    if [[ -n "$CADDY_ID" ]]; then
        SAVED_DOMAIN="$(docker inspect "$CADDY_ID" --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null | sed -n 's/^DOMAIN=//p' | head -n1 || true)"
        if [[ -n "$SAVED_DOMAIN" ]]; then
            printf '\nDOMAIN=%s\n' "$SAVED_DOMAIN" >> "$ROOT/.env"
            echo "[MuchoCore] Recovered DOMAIN=$SAVED_DOMAIN from the existing Caddy container."
        fi
    fi
fi

if ! grep -q '^DOMAIN=' "$ROOT/.env" 2>/dev/null; then
    echo '[MuchoCore] ERROR: DOMAIN is missing from .env and could not be recovered.' >&2
    echo '[MuchoCore] Add DOMAIN=your-domain.example and run update again.' >&2
    exit 1
fi

grep -q '^ADMIN_USER=' "$ROOT/.env" 2>/dev/null || printf '\nADMIN_USER=admin\n' >> "$ROOT/.env"
grep -q '^TZ=' "$ROOT/.env" 2>/dev/null || printf 'TZ=UTC\n' >> "$ROOT/.env"
grep -q '^TURNSTILE_SITEKEY=' "$ROOT/.env" 2>/dev/null || printf 'TURNSTILE_SITEKEY=\n' >> "$ROOT/.env"
grep -q '^TURNSTILE_SECRET=' "$ROOT/.env" 2>/dev/null || printf 'TURNSTILE_SECRET=\n' >> "$ROOT/.env"
grep -q '^MUCHO_GD_VERSIONS=' "$ROOT/.env" 2>/dev/null || printf 'MUCHO_GD_VERSIONS=all\n' >> "$ROOT/.env"

COMPOSE_ARGS=()
if grep -q '^MUCHO_TUNNEL_TOKEN=' "$ROOT/.env" 2>/dev/null; then
    COMPOSE_ARGS=(-f docker-compose.yml -f docker-compose.tunnel.yml)
fi

if ! git diff --quiet || ! git diff --cached --quiet; then
    echo '[MuchoCore] ERROR: this installation has local changes in tracked files.' >&2
    echo '[MuchoCore] I stopped before reset so your work is not lost.' >&2
    echo '[MuchoCore] Commit or back up your changes, then run update again.' >&2
    exit 1
fi

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
docker compose "${COMPOSE_ARGS[@]}" up -d --build --remove-orphans

echo '[MuchoCore] Updating PHP dependencies...'
docker compose exec -T app composer install --no-dev --optimize-autoloader --no-interaction

echo '[MuchoCore] Applying database migrations...'
docker compose exec -T app php bin/migrate.php migrate

echo '[MuchoCore] Synchronizing admin credentials...'
docker compose exec -T app php bin/mucho-sync-admin.php

echo '[MuchoCore] Checking service status...'
docker compose ps

echo
echo '[MuchoCore] Compatibility profile:'
sed -n 's/^MUCHO_GD_VERSIONS=/  GD versions: /p' "$ROOT/.env" | sed 's/,/, /g'
