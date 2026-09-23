#!/usr/bin/env bash
set -Eeuo pipefail

REPO_URL="${MUCHO_REPO_URL:-https://github.com/IZKGMD/GMDmucho-core.git}"
INSTALL_DIR="${MUCHO_INSTALL_DIR:-/opt/mucho-core}"
DOMAIN="${MUCHO_DOMAIN:-}"
DB_NAME="${MUCHO_DB_NAME:-muchocore}"
DB_USER="${MUCHO_DB_USER:-muchocore_user}"
ADMIN_USER="admin"
# Optional: set MUCHO_TUNNEL_TOKEN to deploy via Cloudflare Tunnel instead of
# binding 80/443 directly. Use this on NAT/CGNAT VPS plans that have no
# dedicated public IPv4 (inbound ports other than SSH are not reachable).
# In the current Cloudflare Dashboard, create/select a tunnel under:
# Dashboard -> Networking -> Tunnels. Add the published applications you need,
# then use the connector token shown for the tunnel. The MuchoCore tunnel
# compose override sends traffic to the internal Caddy service at http://caddy:80.
TUNNEL_TOKEN="${MUCHO_TUNNEL_TOKEN:-}"

log()  { printf '\033[1;32m[MuchoCore]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warning]\033[0m %s\n' "$*" >&2; }
fail() { printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2; exit 1; }
trap 'fail "Failure on line $LINENO. Check the output above."' ERR

[[ $EUID -eq 0 ]] || fail "Run the installer as root: sudo bash install.sh"
command -v apt-get >/dev/null 2>&1 || fail "Debian/Ubuntu-like systems are supported."
command -v systemctl >/dev/null 2>&1 || fail "Linux with systemd is required."

if [[ -z "$DOMAIN" ]]; then
  read -r -p "GDPS domain (for example gdps.example.com): " DOMAIN
fi
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || fail "Invalid domain: $DOMAIN"

log "Installing required packages..."
apt-get update -y
apt-get install -y ca-certificates curl git openssl

log "Checking Docker..."
if ! command -v docker >/dev/null 2>&1; then
  curl -fsSL https://get.docker.com | sh
fi
systemctl enable --now docker
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 was not found."

log "Preparing MuchoCore..."
if [[ -d "$INSTALL_DIR/.git" ]]; then
  git -C "$INSTALL_DIR" fetch --depth=1 origin main
  git -C "$INSTALL_DIR" reset --hard origin/main
else
  rm -rf "$INSTALL_DIR"
  git clone --depth=1 "$REPO_URL" "$INSTALL_DIR"
fi

if [[ ! -f "$INSTALL_DIR/docker-compose.yml" ]]; then
  if git -C "$INSTALL_DIR" ls-remote --exit-code origin refs/heads/feat/easy-deploy >/dev/null 2>&1; then
    log "Main does not contain the deployment files yet; using feat/easy-deploy for this test."
    git -C "$INSTALL_DIR" fetch --depth=1 origin feat/easy-deploy
    git -C "$INSTALL_DIR" reset --hard FETCH_HEAD
  else
    fail "Repository does not contain docker-compose.yml."
  fi
fi

install -d -m 700 "$INSTALL_DIR/.secrets"

if [[ -f "$INSTALL_DIR/.secrets/db_password" ]]; then
  MUCHO_DB_PASSWORD="$(cat "$INSTALL_DIR/.secrets/db_password")"
else
  MUCHO_DB_PASSWORD="$(openssl rand -hex 24)"
fi

if [[ -f "$INSTALL_DIR/.secrets/db_root_password" ]]; then
  MUCHO_DB_ROOT_PASSWORD="$(cat "$INSTALL_DIR/.secrets/db_root_password")"
else
  MUCHO_DB_ROOT_PASSWORD="$(openssl rand -hex 32)"
fi

if [[ -f "$INSTALL_DIR/.secrets/admin_password" ]]; then
  MUCHO_ADMIN_PASSWORD="$(cat "$INSTALL_DIR/.secrets/admin_password")"
else
  log "The admin panel username is: admin"
  read -r -s -p "Create a password for the admin panel (you will use it to log in): " MUCHO_ADMIN_PASSWORD
  printf '\n'
fi
[[ -n "$MUCHO_ADMIN_PASSWORD" ]] || fail "Admin password cannot be empty."

printf '%s' "$MUCHO_DB_PASSWORD" > "$INSTALL_DIR/.secrets/db_password"
printf '%s' "$MUCHO_DB_ROOT_PASSWORD" > "$INSTALL_DIR/.secrets/db_root_password"
printf '%s' "$MUCHO_ADMIN_PASSWORD" > "$INSTALL_DIR/.secrets/admin_password"
chmod 600 "$INSTALL_DIR/.secrets/"*

cat > "$INSTALL_DIR/.env" <<EOFENV
DOMAIN=$DOMAIN
CADDY_ADDRESS=$([[ -n "$TUNNEL_TOKEN" ]] && echo ":80" || echo "$DOMAIN")
DB_NAME=$DB_NAME
DB_USER=$DB_USER
ADMIN_USER=$ADMIN_USER
MUCHO_ACCOUNT_URL=https://$DOMAIN
MUCHO_CUSTOM_CONTENT_URL=https://geometrydashfiles.b-cdn.net
TURNSTILE_SITEKEY=${MUCHO_TURNSTILE_SITEKEY:-}
TURNSTILE_SECRET=${MUCHO_TURNSTILE_SECRET:-}
MUCHO_ADMIN_BOOTSTRAP=/etc/muchocore-admin.php
MUCHO_CONTROL_DIR=/var/lib/muchocore-control
MUCHO_BACKUP_DIR=/var/lib/muchocore-backups
TZ=UTC
EOFENV
if [[ -n "$TUNNEL_TOKEN" ]]; then
  printf 'MUCHO_TUNNEL_TOKEN=%s\n' "$TUNNEL_TOKEN" >> "$INSTALL_DIR/.env"
fi
chmod 600 "$INSTALL_DIR/.env"

[[ -f "$INSTALL_DIR/docker-compose.yml" ]] || fail "Repository does not contain docker-compose.yml."
[[ -f "$INSTALL_DIR/docker/Dockerfile" ]] || fail "Repository does not contain docker/Dockerfile."
[[ -f "$INSTALL_DIR/docker/Caddyfile" ]] || fail "Repository does not contain docker/Caddyfile."

log "Starting MuchoCore..."
cd "$INSTALL_DIR"
if [[ -n "$TUNNEL_TOKEN" ]]; then
  log "Tunnel mode: no inbound ports will be opened; Cloudflare Tunnel provides ingress."
  docker compose -f docker-compose.yml -f docker-compose.tunnel.yml up -d --build --remove-orphans
else
  docker compose up -d --build --remove-orphans
fi

log "Checking server health..."
healthy=0
for _ in {1..20}; do
  if [[ -n "$TUNNEL_TOKEN" ]]; then
    check_url="http://127.0.0.1/health"
  else
    check_url="https://$DOMAIN/health"
  fi
  if curl -4ksSf --connect-timeout 2 --max-time 3 $([[ -z "$TUNNEL_TOKEN" ]] && echo "--resolve $DOMAIN:443:127.0.0.1") "$check_url" 2>/dev/null | grep -qx "1"; then
    healthy=1
    break
  fi
  sleep 2
done

if [[ "$healthy" -eq 1 ]]; then
  log "Local health check passed."
  if curl -4ksSf --connect-timeout 3 --max-time 5 "https://$DOMAIN/health" 2>/dev/null | grep -qx "1"; then
    log "Public health check passed."
  else
    if [[ -n "$TUNNEL_TOKEN" ]]; then
      warn "The server is running locally, but the domain is not reachable through Cloudflare Tunnel yet."
      warn "Check the tunnel status: cd $INSTALL_DIR && sudo docker compose logs cloudflared --tail=50"
      warn "Confirm that the tunnel's published application sends traffic to http://caddy:80."
    else
      warn "The server is running, but the domain is not reachable from this VPS yet."
      warn "Check that DNS points to this VPS and that ports 80 and 443 are open."
    fi
  fi
else
  warn "The services started, but the local health check did not pass in time."
  warn "Run: cd $INSTALL_DIR && sudo docker compose ps"
  warn "Run: cd $INSTALL_DIR && sudo docker compose logs --tail=100"
fi

cat <<EOFOUT

MuchoCore is installed.

GDPS:   https://$DOMAIN
Admin:  https://$DOMAIN/admin/
Health: https://$DOMAIN/health
Path:   $INSTALL_DIR

Admin username: admin

Update:
  sudo $INSTALL_DIR/update.sh

Logs:
  cd $INSTALL_DIR && sudo docker compose logs -f

EOFOUT
