#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"
[[ $EUID -eq 0 ]] || { echo 'Run: sudo ./update.sh' >&2; exit 1; }

# Older MuchoCore versions accidentally overwrote the project .env from inside
# the app container. Recover the domain from the existing Caddy container so
# the first update can repair that installation automatically.
install -d -m 700 "$ROOT/.secrets"

# Hosted test tenant secrets are generated once and preserved across updates.
if [[ ! -s "$ROOT/.secrets/testgdps_db_password" ]]; then
    openssl rand -hex 24 > "$ROOT/.secrets/testgdps_db_password"
fi
if [[ ! -s "$ROOT/.secrets/testgdps_db_root_password" ]]; then
    openssl rand -hex 32 > "$ROOT/.secrets/testgdps_db_root_password"
fi
if [[ ! -s "$ROOT/.secrets/testgdps_admin_password" ]]; then
    openssl rand -base64 24 > "$ROOT/.secrets/testgdps_admin_password"
fi
chmod 600 "$ROOT/.secrets/testgdps_"*

if [[ ! -s "$ROOT/.secrets/cloudsave_key" && ! -s "$ROOT/config/cloudsave.key" ]]; then
    if docker compose ps app >/dev/null 2>&1; then
        docker compose exec -T app cat /var/lib/muchocore/cloudsave.key             > "$ROOT/.secrets/cloudsave_key.tmp" 2>/dev/null || true
        if [[ -s "$ROOT/.secrets/cloudsave_key.tmp" ]]; then
            chmod 600 "$ROOT/.secrets/cloudsave_key.tmp"
            mv "$ROOT/.secrets/cloudsave_key.tmp" "$ROOT/.secrets/cloudsave_key"
        else
            rm -f "$ROOT/.secrets/cloudsave_key.tmp"
        fi
    fi
fi

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

# Load DOMAIN from the installation config before strict-mode functions use it.
DOMAIN="$(sed -n 's/^DOMAIN=//p' "$ROOT/.env" | head -n1 || true)"
[[ -n "$DOMAIN" ]] || {
    echo '[MuchoCore] ERROR: DOMAIN is empty in .env.' >&2
    exit 1
}

# Optional additional hostnames (for example hosted tenant subdomains).
CADDY_EXTRA_HOSTS="$(sed -n 's/^CADDY_EXTRA_HOSTS=//p' "$ROOT/.env" | head -n1 || true)"
if [[ -n "$CADDY_EXTRA_HOSTS" ]]; then
    for host in $CADDY_EXTRA_HOSTS; do
        [[ "$host" =~ ^[A-Za-z0-9.-]+$ ]] || {
            echo "[MuchoCore] ERROR: invalid CADDY_EXTRA_HOSTS entry: $host" >&2
            exit 1
        }
    done
fi

grep -q '^ADMIN_USER=' "$ROOT/.env" 2>/dev/null || printf '\nADMIN_USER=admin\n' >> "$ROOT/.env"
grep -q '^TZ=' "$ROOT/.env" 2>/dev/null || printf 'TZ=UTC\n' >> "$ROOT/.env"
grep -q '^TURNSTILE_SITEKEY=' "$ROOT/.env" 2>/dev/null || printf 'TURNSTILE_SITEKEY=\n' >> "$ROOT/.env"
grep -q '^TURNSTILE_SECRET=' "$ROOT/.env" 2>/dev/null || printf 'TURNSTILE_SECRET=\n' >> "$ROOT/.env"
grep -q '^MUCHO_GD_VERSIONS=' "$ROOT/.env" 2>/dev/null || printf 'MUCHO_GD_VERSIONS=all\n' >> "$ROOT/.env"

normalize_caddy_address() {
  if [[ -n "$TUNNEL_TOKEN" ]]; then
    printf ':80'
    return
  fi

  local host="$DOMAIN"
  host="${host#http://}"
  host="${host#https://}"
  host="${host%%/*}"

  local root="$host"
  if [[ "$host" == www.* ]]; then
    root="${host#www.}"
  fi

  printf 'http://%s http://www.%s https://%s https://www.%s' "$root" "$root" "$root" "$root"
  local extra="${CADDY_EXTRA_HOSTS:-}"
  if [[ -n "$extra" ]]; then
    printf ' %s' "$extra"
  fi
}
# Serve both the canonical host and the legacy www host used by older GD 1.9 clients.
# Preserve :80 for Cloudflare Tunnel mode; otherwise explicitly serve HTTP + HTTPS.
if grep -q '^MUCHO_TUNNEL_TOKEN=' "$ROOT/.env" 2>/dev/null; then
    TUNNEL_TOKEN="$(sed -n 's/^MUCHO_TUNNEL_TOKEN=//p' "$ROOT/.env" | head -n1 || true)"
else
    TUNNEL_TOKEN=""
fi
CADDY_ADDRESS_VALUE="$(normalize_caddy_address)"
CADDY_ADDRESS="\"$CADDY_ADDRESS_VALUE\""
sed -i '/^CADDY_ADDRESS=/d' "$ROOT/.env"
printf 'CADDY_ADDRESS=%s\n' "$CADDY_ADDRESS" >> "$ROOT/.env"

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
