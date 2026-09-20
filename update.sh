#!/usr/bin/env bash

# Copyright (C) 2026 IZK
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

[[ $EUID -eq 0 ]] || {
    echo 'Run: sudo ./update.sh' >&2
    exit 1
}

PREVIOUS_SHA="$(git rev-parse HEAD 2>/dev/null || true)"
ROLLED_BACK=0

rollback_source() {
    if [[ "$PREVIOUS_SHA" =~ ^[0-9a-f]{40}$ ]] &&
       git cat-file -e "$PREVIOUS_SHA^{commit}" 2>/dev/null
    then
        echo '[MuchoCore] Update failed. Restoring previous source revision...'

        git reset --hard "$PREVIOUS_SHA" >/dev/null 2>&1 || true

        if docker compose config --quiet >/dev/null 2>&1; then
            docker compose up -d --build --remove-orphans >/dev/null 2>&1 || true
        fi

        ROLLED_BACK=1
    fi
}

on_error() {
    local line="$1"

    if [[ "$ROLLED_BACK" -eq 0 ]]; then
        rollback_source
    fi

    echo "[MuchoCore] Update failed at line $line." >&2
    echo "[MuchoCore] Check the pre-update database backup in $ROOT/backups/pre-update." >&2
    exit 1
}

trap 'on_error $LINENO' ERR

if ! grep -q '^DOMAIN=' "$ROOT/.env" 2>/dev/null; then
    CADDY_ID="$(
        docker ps -a             --filter 'label=com.docker.compose.service=caddy'             --format '{{.ID}}' |
        head -n1 ||
        true
    )"

    if [[ -n "$CADDY_ID" ]]; then
        SAVED_DOMAIN="$(
            docker inspect "$CADDY_ID"                 --format '{{range .Config.Env}}{{println .}}{{end}}'                 2>/dev/null |
            sed -n 's/^DOMAIN=//p' |
            head -n1 ||
            true
        )"

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

grep -q '^ADMIN_USER=' "$ROOT/.env" 2>/dev/null ||
    printf '\nADMIN_USER=admin\n' >> "$ROOT/.env"

grep -q '^TZ=' "$ROOT/.env" 2>/dev/null ||
    printf 'TZ=UTC\n' >> "$ROOT/.env"

DB_NAME="$(
    sed -n 's/^DB_NAME=//p' "$ROOT/.env" |
    head -n1
)"

if [[ -z "$DB_NAME" || ! "$DB_NAME" =~ ^[A-Za-z0-9_.-]+$ ]]; then
    echo '[MuchoCore] ERROR: DB_NAME is missing or invalid.' >&2
    exit 1
fi

docker compose config --quiet

mkdir -p "$ROOT/backups/pre-update"
chmod 700 "$ROOT/backups" "$ROOT/backups/pre-update"

STAMP="$(date -u +%Y%m%d_%H%M%S)"
BACKUP_BASE="$(printf '%s_%s.sql.gz' "$DB_NAME" "$STAMP")"
BACKUP_TMP="$ROOT/backups/pre-update/$BACKUP_BASE.tmp"
BACKUP_FINAL="$ROOT/backups/pre-update/$BACKUP_BASE"

echo "[MuchoCore] Creating pre-update database backup..."

if ! docker compose exec -T db \
    sh -c 'mariadb-dump \
        --single-transaction \
        --quick \
        --triggers \
        --hex-blob \
        --default-character-set=utf8mb4 \
        -u root \
        --password="$(cat /run/secrets/db_root_password)" \
        "$1"' sh "$DB_NAME" |\
    gzip -9 > "$BACKUP_TMP"
then
    rm -f "$BACKUP_TMP"
    echo '[MuchoCore] ERROR: pre-update database backup failed. Update cancelled.' >&2
    exit 1
fi

gzip -t "$BACKUP_TMP"

if [[ "$(stat -c '%s' "$BACKUP_TMP")" -le 100 ]]; then
    rm -f "$BACKUP_TMP"
    echo '[MuchoCore] ERROR: pre-update database backup is unexpectedly small. Update cancelled.' >&2
    exit 1
fi

mv "$BACKUP_TMP" "$BACKUP_FINAL"
chmod 600 "$BACKUP_FINAL"

sha256sum "$BACKUP_FINAL" > "$BACKUP_FINAL.sha256"
chmod 600 "$BACKUP_FINAL.sha256"

echo "[MuchoCore] Database backup saved: $BACKUP_FINAL"

echo '[MuchoCore] Updating source code...'
git fetch --depth=1 origin main

if git show origin/main:docker-compose.yml >/dev/null 2>&1; then
    git reset --hard origin/main
elif git ls-remote --exit-code origin refs/heads/feat/easy-deploy >/dev/null 2>&1; then
    echo '[MuchoCore] Main does not contain the deployment files yet; using feat/easy-deploy.'
    git fetch --depth=1 origin feat/easy-deploy
    git reset --hard FETCH_HEAD
else
    echo '[MuchoCore] ERROR: the deployment files are not available on main or feat/easy-deploy.' >&2
    exit 1
fi

echo '[MuchoCore] Rebuilding containers...'
docker compose up -d --build --remove-orphans

echo '[MuchoCore] Updating PHP dependencies...'
docker compose exec -T app     composer install     --no-dev     --optimize-autoloader     --no-interaction

echo '[MuchoCore] Applying database migrations...'
docker compose exec -T app     php bin/migrate.php migrate

echo '[MuchoCore] Checking API + database health...'
healthy=0

for _ in {1..30}; do
    if curl -4ksSf         --connect-timeout 2         --max-time 5         --resolve "$DOMAIN:443:127.0.0.1"         "https://$DOMAIN/api/v2/health.php" 2>/dev/null |
        grep -q '"ok"[[:space:]]*:[[:space:]]*true'
    then
        healthy=1
        break
    fi

    sleep 2
done

if [[ "$healthy" -ne 1 ]]; then
    docker compose ps || true
    docker compose logs --tail=80 || true

    echo '[MuchoCore] ERROR: health check failed after update.' >&2
    exit 1
fi

echo '[MuchoCore] Update completed successfully.'
echo "[MuchoCore] Source: $(git rev-parse --short HEAD)"
echo "[MuchoCore] Pre-update DB backup: $BACKUP_FINAL"
