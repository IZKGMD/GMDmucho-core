#!/usr/bin/env bash
set -Eeuo pipefail

REPO_URL="${MUCHO_REPO_URL:-https://github.com/IZKGMD/GMDmucho-core.git}"
INSTALL_DIR="${MUCHO_INSTALL_DIR:-/opt/mucho-core}"
DOMAIN="${MUCHO_DOMAIN:-}"
DB_NAME="${MUCHO_DB_NAME:-}"
DB_USER="${MUCHO_DB_USER:-}"
ADMIN_USER="${MUCHO_ADMIN_USER:-}"
CUSTOM_CONTENT_URL="${MUCHO_CUSTOM_CONTENT_URL:-}"
TURNSTILE_SITEKEY="${MUCHO_TURNSTILE_SITEKEY:-}"
TURNSTILE_SECRET="${MUCHO_TURNSTILE_SECRET:-}"
MUCHO_ADMIN_PASSWORD="${MUCHO_ADMIN_PASSWORD:-}"
# Optional: set MUCHO_TUNNEL_TOKEN to deploy via Cloudflare Tunnel instead of
# binding 80/443 directly. Use this on NAT/CGNAT VPS plans that have no
# dedicated public IPv4 (inbound ports other than SSH are not reachable).
# In the current Cloudflare Dashboard, create/select a tunnel under:
# Dashboard -> Networking -> Tunnels. Add the published applications you need,
# then use the connector token shown for the tunnel. The MuchoCore tunnel
# compose override sends traffic to the internal Caddy service at http://caddy:80.
TUNNEL_TOKEN="${MUCHO_TUNNEL_TOKEN:-}"
GD_VERSIONS="${MUCHO_GD_VERSIONS:-}"

BOLD='\033[1m'
CYAN='\033[1;36m'
GREEN='\033[1;32m'
YELLOW='\033[1;33m'
RED='\033[1;31m'
RESET='\033[0m'

log()  { printf "${GREEN}[MuchoCore]${RESET} %s\n" "$*"; }
info() { printf "  ${CYAN}→${RESET} %s\n" "$*"; }
warn() { printf "\n${YELLOW}[warning]${RESET} %s\n" "$*" >&2; }
fail() { printf "\n${RED}[error]${RESET} %s\n" "$*" >&2; exit 1; }

print_banner() {
  printf "\n"
  printf "${CYAN}╔══════════════════════════════════════════════════════════════════════╗${RESET}\n"
  printf "${CYAN}║${RESET}                                                                      ${CYAN}║${RESET}\n"
  printf "${CYAN}║${RESET}   ${BOLD}███╗   ███╗██╗   ██╗ ██████╗██╗  ██╗ ██████╗${RESET}               ${CYAN}║${RESET}\n"
  printf "${CYAN}║${RESET}   ${BOLD}████╗ ████║██║   ██║██╔════╝██║  ██║██╔═══██╗${RESET}               ${CYAN}║${RESET}\n"
  printf "${CYAN}║${RESET}   ${BOLD}██╔████╔██║██║   ██║██║     ███████║██║   ██║${RESET}               ${CYAN}║${RESET}\n"
  printf "${CYAN}║${RESET}   ${BOLD}██║╚██╔╝██║██║   ██║██║     ██╔══██║██║   ██║${RESET}               ${CYAN}║${RESET}\n"
  printf "${CYAN}║${RESET}   ${BOLD}██║ ╚═╝ ██║╚██████╔╝╚██████╗██║  ██║╚██████╔╝${RESET}               ${CYAN}║${RESET}\n"
  printf "${CYAN}║${RESET}   ${BOLD}╚═╝     ╚═╝ ╚═════╝  ╚═════╝╚═╝  ╚═╝ ╚═════╝${RESET}               ${CYAN}║${RESET}\n"
  printf "${CYAN}║${RESET}                                                                      ${CYAN}║${RESET}\n"
  printf "${CYAN}║${RESET}                 ${BOLD}MUCHOCORE • v1.0.0 INSTALLER${RESET}                  ${CYAN}║${RESET}\n"
  printf "${CYAN}║${RESET}            Geometry Dash Private Server deployment                ${CYAN}║${RESET}\n"
  printf "${CYAN}║${RESET}                                                                      ${CYAN}║${RESET}\n"
  printf "${CYAN}╚══════════════════════════════════════════════════════════════════════╝${RESET}\n"
}
profile_label() {
  case "$1" in
    all) printf "GD 1.9 → 2.2" ;;
    19) printf "GD 1.9" ;;
    20) printf "GD 2.0" ;;
    21) printf "GD 2.1" ;;
    22) printf "GD 2.2" ;;
    *) printf "%s" "$1" | sed "s/19/1.9/g; s/20/2.0/g; s/21/2.1/g; s/22/2.2/g; s/,/, /g" ;;
  esac
}

profile_choice() {
  case "$1" in
    all) printf "1" ;;
    19) printf "2" ;;
    20) printf "3" ;;
    21) printf "4" ;;
    22) printf "5" ;;
    *) printf "6" ;;
  esac
}

valid_versions() {
  case "$1" in
    all|19|20|21|22|19,20|19,21|19,22|20,21|20,22|21,22|19,20,21|19,20,22|19,21,22|20,21,22|19,20,21,22)
      return 0 ;;
    *) return 1 ;;
  esac
}

select_compatibility_profile() {
  if [[ -z "$GD_VERSIONS" ]]; then
    GD_VERSIONS="all"
  fi
  [[ "$GD_VERSIONS" == "ALL" || "$GD_VERSIONS" == "All" ]] && GD_VERSIONS="all"
  valid_versions "$GD_VERSIONS" ||
    fail "Invalid MUCHO_GD_VERSIONS='$GD_VERSIONS'. Use all or a comma-separated set of 19,20,21,22."

  if [[ ! -t 0 && ! -t 1 ]]; then
    info "Compatibility profile: $(profile_label "$GD_VERSIONS")"
    return
  fi

  print_banner
  printf "${BOLD}  Choose your Geometry Dash compatibility profile${RESET}\n"
  printf "  ${CYAN}The selected profile is saved and shown as the default next time.${RESET}\n\n"

  local current_choice choice
  current_choice="$(profile_choice "$GD_VERSIONS")"

  printf "  ${CYAN}1${RESET}) ${BOLD}All supported versions${RESET}   GD 1.9 → 2.2"
  [[ "$current_choice" == "1" ]] && printf "  ${GREEN}← current${RESET}"
  printf "\n"
  printf "  ${CYAN}2${RESET}) GD 1.9 only              Legacy"
  [[ "$current_choice" == "2" ]] && printf "  ${GREEN}← current${RESET}"
  printf "\n"
  printf "  ${CYAN}3${RESET}) GD 2.0 only"
  [[ "$current_choice" == "3" ]] && printf "  ${GREEN}← current${RESET}"
  printf "\n"
  printf "  ${CYAN}4${RESET}) GD 2.1 only"
  [[ "$current_choice" == "4" ]] && printf "  ${GREEN}← current${RESET}"
  printf "\n"
  printf "  ${CYAN}5${RESET}) GD 2.2 only"
  [[ "$current_choice" == "5" ]] && printf "  ${GREEN}← current${RESET}"
  printf "\n"
  printf "  ${CYAN}6${RESET}) Custom profile            e.g. 19,22"
  [[ "$current_choice" == "6" ]] && printf "  ${GREEN}← current${RESET}"
  printf "\n\n"

  read -r -p "  Select [$current_choice]: " choice < /dev/tty || choice="$current_choice"
  if [[ -z "$choice" ]]; then choice="$current_choice"; fi

  case "$choice" in
    1) GD_VERSIONS="all" ;;
    2) GD_VERSIONS="19" ;;
    3) GD_VERSIONS="20" ;;
    4) GD_VERSIONS="21" ;;
    5) GD_VERSIONS="22" ;;
    6)
      read -r -p "  Versions [19,20,21,22]: " GD_VERSIONS < /dev/tty
      valid_versions "$GD_VERSIONS" || fail "Invalid version profile."
      ;;
    *) fail "Invalid selection." ;;
  esac

  printf "\n"
  info "Selected: $(profile_label "$GD_VERSIONS")"
}

preflight() {
  log "Running preflight checks..."

  local free_kib
  free_kib="$(df -Pk "$(dirname "$INSTALL_DIR")" 2>/dev/null | awk 'NR==2 {print $4}')"
  [[ -n "$free_kib" && "$free_kib" -ge 1048576 ]] ||
    fail "At least 1 GiB of free disk space is required."

  local mem_kib
  mem_kib="$(awk '/MemAvailable:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)"
  if [[ "$mem_kib" -lt 524288 ]]; then
    warn "Less than 512 MiB of available RAM detected. Docker builds may fail."
  fi

  info "Disk and memory checks passed."
}

trap 'fail "Failure on line $LINENO. Check the output above."' ERR

[[ $EUID -eq 0 ]] || fail "Run the installer as root: sudo bash install.sh"

if [[ -f "$INSTALL_DIR/.env" ]]; then
  if [[ -z "$GD_VERSIONS" ]]; then
    GD_VERSIONS="$(sed -n 's/^MUCHO_GD_VERSIONS=//p' "$INSTALL_DIR/.env" | head -n1)"
  fi
  if [[ -z "$TUNNEL_TOKEN" ]]; then
    TUNNEL_TOKEN="$(sed -n 's/^MUCHO_TUNNEL_TOKEN=//p' "$INSTALL_DIR/.env" | head -n1)"
  fi
  if [[ -z "$DOMAIN" ]]; then
    DOMAIN="$(sed -n 's/^DOMAIN=//p' "$INSTALL_DIR/.env" | head -n1)"
  fi
  if [[ -z "$DB_NAME" ]]; then
    DB_NAME="$(sed -n 's/^DB_NAME=//p' "$INSTALL_DIR/.env" | head -n1)"
  fi
  if [[ -z "$DB_USER" ]]; then
    DB_USER="$(sed -n 's/^DB_USER=//p' "$INSTALL_DIR/.env" | head -n1)"
  fi
  if [[ -z "$ADMIN_USER" ]]; then
    ADMIN_USER="$(sed -n 's/^ADMIN_USER=//p' "$INSTALL_DIR/.env" | head -n1)"
  fi
  if [[ -z "$CUSTOM_CONTENT_URL" ]]; then
    CUSTOM_CONTENT_URL="$(sed -n 's/^MUCHO_CUSTOM_CONTENT_URL=//p' "$INSTALL_DIR/.env" | head -n1)"
  fi
  if [[ -z "$TURNSTILE_SITEKEY" ]]; then
    TURNSTILE_SITEKEY="$(sed -n 's/^TURNSTILE_SITEKEY=//p' "$INSTALL_DIR/.env" | head -n1)"
  fi
  if [[ -z "$TURNSTILE_SECRET" ]]; then
    TURNSTILE_SECRET="$(sed -n 's/^TURNSTILE_SECRET=//p' "$INSTALL_DIR/.env" | head -n1)"
  fi
fi

DB_NAME="${DB_NAME:-muchocore}"
DB_USER="${DB_USER:-muchocore_user}"
ADMIN_USER="${ADMIN_USER:-admin}"
CUSTOM_CONTENT_URL="${CUSTOM_CONTENT_URL:-https://geometrydashfiles.b-cdn.net}"

select_compatibility_profile

if [[ -z "$DOMAIN" ]]; then
  read -r -p "  GDPS domain (for example gdps.example.com): " DOMAIN < /dev/tty
fi
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || fail "Invalid domain: $DOMAIN"

preflight

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
elif [[ -z "$MUCHO_ADMIN_PASSWORD" ]]; then
  log "The admin panel username is: admin"
  read -r -s -p "Create a password for the admin panel (you will use it to log in): " MUCHO_ADMIN_PASSWORD < /dev/tty
  printf '\n'
fi
[[ -n "$MUCHO_ADMIN_PASSWORD" ]] || fail "Admin password cannot be empty."

printf '%s' "$MUCHO_DB_PASSWORD" > "$INSTALL_DIR/.secrets/db_password"
printf '%s' "$MUCHO_DB_ROOT_PASSWORD" > "$INSTALL_DIR/.secrets/db_root_password"
printf '%s' "$MUCHO_ADMIN_PASSWORD" > "$INSTALL_DIR/.secrets/admin_password"
chmod 600 "$INSTALL_DIR/.secrets/"*

if [[ -f "$INSTALL_DIR/.env" ]]; then
  backup_file="$INSTALL_DIR/.env.backup.$(date +%Y%m%d-%H%M%S)"
  cp "$INSTALL_DIR/.env" "$backup_file"
  chmod 600 "$backup_file"
  info "Backed up existing .env to $(basename "$backup_file")"
fi

cat > "$INSTALL_DIR/.env" <<EOFENV
DOMAIN=$DOMAIN
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
}
CADDY_ADDRESS_VALUE="$(normalize_caddy_address)"
CADDY_ADDRESS="\"$CADDY_ADDRESS_VALUE\""
DB_NAME=$DB_NAME
DB_USER=$DB_USER
ADMIN_USER=$ADMIN_USER
MUCHO_ACCOUNT_URL=https://$DOMAIN
MUCHO_CUSTOM_CONTENT_URL=$CUSTOM_CONTENT_URL
TURNSTILE_SITEKEY=$TURNSTILE_SITEKEY
TURNSTILE_SECRET=$TURNSTILE_SECRET
MUCHO_ADMIN_BOOTSTRAP=/etc/muchocore-admin.php
MUCHO_CONTROL_DIR=/var/lib/muchocore-control
MUCHO_BACKUP_DIR=/var/lib/muchocore-backups
TZ=UTC
MUCHO_GD_VERSIONS=$GD_VERSIONS
EOFENV
if [[ -n "$TUNNEL_TOKEN" ]]; then
  printf 'MUCHO_TUNNEL_TOKEN=%s\n' "$TUNNEL_TOKEN" >> "$INSTALL_DIR/.env"
fi
chmod 600 "$INSTALL_DIR/.env"

install -d -m 700 "$INSTALL_DIR/.muchocore"
cat > "$INSTALL_DIR/.muchocore/profile.env" <<EOFPROFILE
MUCHO_GD_VERSIONS=$GD_VERSIONS
EOFPROFILE
chmod 600 "$INSTALL_DIR/.muchocore/profile.env"

[[ -f "$INSTALL_DIR/docker-compose.yml" ]] || fail "Repository does not contain docker-compose.yml."
[[ -f "$INSTALL_DIR/docker/Dockerfile" ]] || fail "Repository does not contain docker/Dockerfile."
[[ -f "$INSTALL_DIR/docker/Caddyfile" ]] || fail "Repository does not contain docker/Caddyfile."

log "Validating Docker Compose..."
if [[ -n "$TUNNEL_TOKEN" ]]; then
  docker compose -f docker-compose.yml -f docker-compose.tunnel.yml config -q
else
  docker compose config -q
fi

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

log "Running database migrations..."
docker compose exec -T app php bin/migrate.php migrate

cat <<EOFOUT

MuchoCore is installed.

Compatibility profile:
  GD_VERSIONS=$GD_VERSIONS

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
