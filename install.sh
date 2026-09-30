#!/usr/bin/env bash
set -Eeuo pipefail

REPO_URL="${MUCHO_REPO_URL:-https://github.com/IZKGMD/GMDmucho-core.git}"
INSTALL_DIR="${MUCHO_INSTALL_DIR:-/opt/mucho-core}"
DOMAIN="${MUCHO_DOMAIN:-}"
DB_NAME="${MUCHO_DB_NAME:-}"
DB_USER="${MUCHO_DB_USER:-}"
ADMIN_USER="${MUCHO_ADMIN_USER:-}"
CADDY_EXTRA_HOSTS="${MUCHO_CADDY_EXTRA_HOSTS:-}"
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
RELEASE_API="${MUCHO_RELEASE_API:-https://api.github.com/repos/IZKGMD/GMDmucho-core/releases/latest}"

get_latest_stable_release_tag() {
  local response tag
response="$(curl -4fsS --retry 3 --retry-delay 1 \
    --connect-timeout 5 --max-time 15 \
    -H 'Accept: application/vnd.github+json' \
    -H 'User-Agent: MuchoCore-Installer/1.1' \
    -H 'X-GitHub-Api-Version: 2022-11-28' \
    "$RELEASE_API")" || return 1

  command -v jq >/dev/null 2>&1 || return 1
  tag="$(printf '%s' "$response" | jq -r '.tag_name // empty')"

  [[ "$tag" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+$ ]] || return 1
  printf '%s' "$tag"
}

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

# The installer is intentionally interactive only for the two values that are
# genuinely deployment-specific: the public domain and the initial admin
# password. Everything else has safe production defaults and can be changed
# later through "sudo mucho".
QUICK_MODE=0
INSTALL_STEP="starting"
MUCHO_MIGRATION_ON_INSTALL="${MUCHO_MIGRATION_ON_INSTALL:-}"

usage() {
  cat <<'EOF'
MuchoCore installer

Quick interactive install:
  curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install.sh | sudo bash

Optional flags:
  --quick                 Skip compatibility selection (defaults to all).
  --domain=HOST           Set the GDPS hostname without prompting.
  --gd-versions=PROFILE   Set all, or a comma-separated profile such as 19,22.
  --admin-password=PASS   Set the initial admin password without prompting.
  --migrate               Open the existing-GDPS migration flow after install.
  --help                  Show this help.

Environment equivalents are also supported.
EOF
}

for arg in "$@"; do
  case "$arg" in
    --quick) QUICK_MODE=1 ;;
    --domain=*) DOMAIN="${arg#*=}" ;;
    --gd-versions=*) GD_VERSIONS="${arg#*=}" ;;
    --admin-password=*) MUCHO_ADMIN_PASSWORD="${arg#*=}" ;;
    --migrate) MUCHO_MIGRATION_ON_INSTALL=2 ;;
    --help|-h) usage; exit 0 ;;
    --*) fail "Unknown installer option: $arg. Run --help for supported options." ;;
    *) fail "Unexpected installer argument: $arg. Run --help for supported options." ;;
  esac
done


# Prevent two MuchoCore installations from changing the same host at once.
exec 9>/run/muchocore-install.lock
if ! flock -n 9; then
  fail "Another MuchoCore installation is already running."
fi

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
  printf "${CYAN}║${RESET}                 ${BOLD}MUCHOCORE • STABLE RELEASE INSTALLER${RESET}          ${CYAN}║${RESET}\n"
  printf "${CYAN}║${RESET}            Geometry Dash Private Server deployment                ${CYAN}║${RESET}\n"
  printf "${CYAN}║${RESET}                                                                      ${CYAN}║${RESET}\n"
  printf "${CYAN}╚══════════════════════════════════════════════════════════════════════╝${RESET}\n"
}
profile_label() {
  case "$1" in
    all) printf "GD 1.0 → 2.2" ;;
    1|10) printf "GD 1.0" ;;
    11) printf "GD 1.1" ;;
    19) printf "GD 1.9" ;;
    20) printf "GD 2.0" ;;
    21) printf "GD 2.1" ;;
    22) printf "GD 2.2" ;;
    *)
      local value
      local -a values labels
      IFS="," read -r -a values <<< "$1"
      labels=()
      for value in "${values[@]}"; do
        case "$value" in
          1|10) labels+=("GD 1.0") ;;
          11) labels+=("GD 1.1") ;;
          19) labels+=("GD 1.9") ;;
          20) labels+=("GD 2.0") ;;
          21) labels+=("GD 2.1") ;;
          22) labels+=("GD 2.2") ;;
          *) labels+=("$value") ;;
        esac
      done
      local IFS=", "
      printf "%s" "${labels[*]}"
      ;;
  esac
}

profile_choice() {
  case "$1" in
    all) printf "1" ;;
    1|10) printf "2" ;;
    11) printf "3" ;;
    19) printf "4" ;;
    20) printf "5" ;;
    21) printf "6" ;;
    22) printf "7" ;;
    *) printf "8" ;;
  esac
}

valid_versions() {
  [[ "$1" == "all" ]] && return 0
  local value
  local -a values
  IFS="," read -r -a values <<< "$1"
  ((${#values[@]} > 0)) || return 1
  for value in "${values[@]}"; do
    value="${value//[[:space:]]/}"
    case "$value" in
      1|10|11|19|20|21|22) ;;
      *) return 1 ;;
    esac
  done
  return 0
}

select_compatibility_profile() {
  if [[ -z "$GD_VERSIONS" ]]; then
    GD_VERSIONS="all"
  fi
  [[ "$GD_VERSIONS" == "ALL" || "$GD_VERSIONS" == "All" ]] && GD_VERSIONS="all"
  valid_versions "$GD_VERSIONS" ||
    fail "Invalid MUCHO_GD_VERSIONS='$GD_VERSIONS'. Use all or a comma-separated set of 1,11,19,20,21,22."

  if [[ ! -t 0 || ! -t 1 || "$QUICK_MODE" -eq 1 ]]; then
    info "Compatibility profile: $(profile_label "$GD_VERSIONS")"
    return
  fi

  print_banner
  printf "${BOLD}  Choose your Geometry Dash compatibility profile${RESET}\n"
  printf "  ${CYAN}The selected profile is saved and shown as the default next time.${RESET}\n\n"

  local current_choice choice
  current_choice="$(profile_choice "$GD_VERSIONS")"

  printf "  ${CYAN}1${RESET}) ${BOLD}All supported versions${RESET}   GD 1.0 → 2.2"
  [[ "$current_choice" == "1" ]] && printf "  ${GREEN}← current${RESET}"
  printf "\n"
  printf "  ${CYAN}2${RESET}) GD 1.0 only"
  [[ "$current_choice" == "2" ]] && printf "  ${GREEN}← current${RESET}"
  printf "\n"
  printf "  ${CYAN}3${RESET}) GD 1.1 only"
  [[ "$current_choice" == "3" ]] && printf "  ${GREEN}← current${RESET}"
  printf "\n"
  printf "  ${CYAN}4${RESET}) GD 1.9 only"
  [[ "$current_choice" == "4" ]] && printf "  ${GREEN}← current${RESET}"
  printf "\n"
  printf "  ${CYAN}5${RESET}) GD 2.0 only"
  [[ "$current_choice" == "5" ]] && printf "  ${GREEN}← current${RESET}"
  printf "\n"
  printf "  ${CYAN}6${RESET}) GD 2.1 only"
  [[ "$current_choice" == "6" ]] && printf "  ${GREEN}← current${RESET}"
  printf "\n"
  printf "  ${CYAN}7${RESET}) GD 2.2 only"
  [[ "$current_choice" == "7" ]] && printf "  ${GREEN}← current${RESET}"
  printf "\n"
  printf "  ${CYAN}8${RESET}) Custom profile            e.g. 11,19,22"
  [[ "$current_choice" == "8" ]] && printf "  ${GREEN}← current${RESET}"
  printf "\n\n"

  read -r -p "  Select [$current_choice]: " choice < /dev/tty || choice="$current_choice"
  if [[ -z "$choice" ]]; then choice="$current_choice"; fi

  case "$choice" in
    1) GD_VERSIONS="all" ;;
    2) GD_VERSIONS="1" ;;
    3) GD_VERSIONS="11" ;;
    4) GD_VERSIONS="19" ;;
    5) GD_VERSIONS="20" ;;
    6) GD_VERSIONS="21" ;;
    7) GD_VERSIONS="22" ;;
    8)
      read -r -p "  Versions [1,11,19,20,21,22]: " GD_VERSIONS < /dev/tty
      valid_versions "$GD_VERSIONS" || fail "Invalid version profile."
      ;;
    *) fail "Invalid selection." ;;
  esac

  printf "\n"
  info "Selected: $(profile_label "$GD_VERSIONS")"
}

preflight() {
  log "Running preflight checks..."

  command -v apt-get >/dev/null 2>&1 || fail "This installer requires apt-get (Debian/Ubuntu-style VPS)."
  command -v systemctl >/dev/null 2>&1 || fail "systemd is required to manage Docker automatically."

  case "$(uname -m)" in
    x86_64|amd64|aarch64|arm64) ;;
    *) fail "Unsupported CPU architecture: $(uname -m)." ;;
  esac


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

trap 'fail "Installation failed during: $INSTALL_STEP (line $LINENO). Check the output above, then run: sudo mucho doctor"' ERR

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
DOMAIN="${DOMAIN#http://}"
DOMAIN="${DOMAIN#https://}"
DOMAIN="${DOMAIN%%/*}"
fi
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || fail "Invalid domain: $DOMAIN"

if [[ -n "$CADDY_EXTRA_HOSTS" ]]; then
  for host in $CADDY_EXTRA_HOSTS; do
    [[ "$host" =~ ^[A-Za-z0-9.-]+$ ]] || fail "Invalid CADDY_EXTRA_HOSTS entry: $host"
  done
fi

check_domain_preflight() {
  INSTALL_STEP="checking domain and host networking"
  info "Checking domain and host networking..."

  local domain_ips public_ip port_80 port_443 caddy_running
  domain_ips="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ' | sed 's/[[:space:]]*$//' || true)"

  if [[ -n "$domain_ips" ]]; then
    info "DNS resolves: $DOMAIN → $domain_ips"
  else
    warn "DNS for $DOMAIN does not resolve from this VPS yet."
    warn "Installation can continue, but public HTTPS will not work until DNS points at this server."
  fi

  public_ip="$(curl -4fsS --connect-timeout 3 --max-time 5 https://api.ipify.org 2>/dev/null || true)"
  if [[ -n "$public_ip" && -n "$domain_ips" ]]; then
    if ! printf '%s\n' "$domain_ips" | tr ' ' '\n' | grep -Fxq "$public_ip"; then
      warn "DNS does not currently resolve to this VPS public IPv4 ($public_ip)."
      warn "Expected one of: $domain_ips"
    else
      info "DNS points to this VPS."
    fi
  fi

  if [[ -z "$TUNNEL_TOKEN" && "$(command -v ss || true)" ]]; then
    port_80="$(ss -ltnH 2>/dev/null | awk '$4 ~ /:80$/ {print; exit}' || true)"
    port_443="$(ss -ltnH 2>/dev/null | awk '$4 ~ /:443$/ {print; exit}' || true)"
    caddy_running="$(docker ps --filter 'label=com.docker.compose.service=caddy' --format '{{.ID}}' 2>/dev/null | head -n1 || true)"

    if [[ -n "$port_80" || -n "$port_443" ]] && [[ -z "$caddy_running" ]]; then
      local occupied=""
      [[ -n "$port_80" ]] && occupied="80"
      [[ -n "$port_443" ]] && occupied="${occupied:+$occupied,}443"
      fail "Ports $occupied are already in use. Stop the service using them (often nginx/apache) and run the installer again."
    fi
  fi
}

check_domain_preflight
preflight

INSTALL_STEP="installing host prerequisites"
log "Installing required packages..."
DEBIAN_FRONTEND=noninteractive apt-get update -y
DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl git jq openssl

INSTALL_STEP="checking Docker"
log "Checking Docker..."
if ! command -v docker >/dev/null 2>&1; then
  curl -fsSL https://get.docker.com | sh
fi
systemctl enable --now docker
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 was not found."

INSTALL_STEP="fetching the stable MuchoCore release"
log "Preparing MuchoCore..."
LATEST_RELEASE_TAG="$(get_latest_stable_release_tag)" ||
  fail "Unable to resolve a published stable MuchoCore Release from GitHub."

if [[ -d "$INSTALL_DIR/.git" ]]; then
  if ! git -C "$INSTALL_DIR" diff --quiet || ! git -C "$INSTALL_DIR" diff --cached --quiet; then
    fail "Existing MuchoCore installation has local tracked changes. Commit or back them up before re-running install.sh."
  fi
  git -C "$INSTALL_DIR" fetch --depth=1 origin "refs/tags/$LATEST_RELEASE_TAG:refs/tags/$LATEST_RELEASE_TAG"
  git -C "$INSTALL_DIR" reset --hard "$LATEST_RELEASE_TAG"
else
  rm -rf "$INSTALL_DIR"
  git clone --depth=1 --branch "$LATEST_RELEASE_TAG" "$REPO_URL" "$INSTALL_DIR"
fi

[[ -f "$INSTALL_DIR/docker-compose.yml" ]] ||
  fail "The selected stable release does not contain docker-compose.yml."

if [[ -x "$INSTALL_DIR/bin/mucho" ]]; then ln -sfn "$INSTALL_DIR/bin/mucho" /usr/local/bin/mucho; fi
if [[ -x "$INSTALL_DIR/bin/muchodb-password" ]]; then ln -sfn "$INSTALL_DIR/bin/muchodb-password" /usr/local/bin/muchodb-password; fi

install -d -m 700 "$INSTALL_DIR/.secrets"

if [[ -s "$INSTALL_DIR/.secrets/db_password" ]]; then
  MUCHO_DB_PASSWORD="$(cat "$INSTALL_DIR/.secrets/db_password")"
else
  MUCHO_DB_PASSWORD="$(openssl rand -hex 24)"
fi

if [[ -s "$INSTALL_DIR/.secrets/db_root_password" ]]; then
  MUCHO_DB_ROOT_PASSWORD="$(cat "$INSTALL_DIR/.secrets/db_root_password")"
else
  MUCHO_DB_ROOT_PASSWORD="$(openssl rand -hex 32)"
fi

if [[ -s "$INSTALL_DIR/.secrets/admin_password" ]]; then
  MUCHO_ADMIN_PASSWORD="$(cat "$INSTALL_DIR/.secrets/admin_password")"
elif [[ -z "$MUCHO_ADMIN_PASSWORD" ]]; then
  log "The admin panel username is: $ADMIN_USER"
  read -r -s -p "Create a password for the admin panel (you will use it to log in): " MUCHO_ADMIN_PASSWORD < /dev/tty
  printf '\n'
fi
[[ -n "$MUCHO_ADMIN_PASSWORD" ]] || fail "Admin password cannot be empty."

printf '%s' "$MUCHO_DB_PASSWORD" > "$INSTALL_DIR/.secrets/db_password"
printf '%s' "$MUCHO_DB_ROOT_PASSWORD" > "$INSTALL_DIR/.secrets/db_root_password"
printf '%s' "$MUCHO_ADMIN_PASSWORD" > "$INSTALL_DIR/.secrets/admin_password"
if [[ ! -f "$INSTALL_DIR/.secrets/cloudsave_key" && -f "$INSTALL_DIR/config/cloudsave.key" ]]; then
  cp "$INSTALL_DIR/config/cloudsave.key" "$INSTALL_DIR/.secrets/cloudsave_key"
  chmod 600 "$INSTALL_DIR/.secrets/cloudsave_key"
fi

if [[ -s "$INSTALL_DIR/.secrets/cloudsave_key" ]]; then
  MUCHO_CLOUDSAVE_KEY="$(cat "$INSTALL_DIR/.secrets/cloudsave_key")"
else
  MUCHO_CLOUDSAVE_KEY="$(openssl rand -base64 32)"
fi
printf '%s\n' "$MUCHO_CLOUDSAVE_KEY" > "$INSTALL_DIR/.secrets/cloudsave_key"
chmod 600 "$INSTALL_DIR/.secrets/"*

for secret in \
  "$INSTALL_DIR/.secrets/db_password" \
  "$INSTALL_DIR/.secrets/db_root_password" \
  "$INSTALL_DIR/.secrets/admin_password" \
  "$INSTALL_DIR/.secrets/cloudsave_key" \
  "$INSTALL_DIR/.secrets/testgdps_db_password" \
  "$INSTALL_DIR/.secrets/testgdps_db_root_password" \
  "$INSTALL_DIR/.secrets/testgdps_admin_password"; do
  [[ -s "$secret" ]] || fail "Required secret file is missing or empty: $secret"
done

if [[ -f "$INSTALL_DIR/.env" ]]; then
  backup_file="$INSTALL_DIR/.env.backup.$(date +%Y%m%d-%H%M%S)"
  cp "$INSTALL_DIR/.env" "$backup_file"
  chmod 600 "$backup_file"
  info "Backed up existing .env to $(basename "$backup_file")"
fi

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

  printf '%s www.%s' "$root" "$root"
  if [[ -n "${CADDY_EXTRA_HOSTS:-}" ]]; then
    printf ' %s' "$CADDY_EXTRA_HOSTS"
  fi
}

CADDY_ADDRESS_VALUE="$(normalize_caddy_address)"

cat > "$INSTALL_DIR/.env" <<EOFENV
DOMAIN=$DOMAIN
CADDY_ADDRESS_VALUE="$CADDY_ADDRESS_VALUE"
CADDY_ADDRESS="$CADDY_ADDRESS_VALUE"
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
CADDY_EXTRA_HOSTS=$CADDY_EXTRA_HOSTS
MUCHOCORE_SITE_HOST=disabled.invalid
MUCHO_PROTECT_STORAGE=file
MUCHO_TRUSTED_PROXY_CIDRS=
MUCHO_AUTO_UPDATE=1
MUCHO_AUTO_UPDATE_INTERVAL=15min
EOFENV
if [[ -n "$TUNNEL_TOKEN" ]]; then
  printf 'MUCHO_TUNNEL_TOKEN=%s\n' "$TUNNEL_TOKEN" >> "$INSTALL_DIR/.env"
fi
chmod 600 "$INSTALL_DIR/.env"

if [[ -f "$INSTALL_DIR/bin/mucho-install-auto-update.sh" ]]; then
  INSTALL_STEP="configuring automatic updates"
    log "Configuring release-based automatic updates..."
  bash "$INSTALL_DIR/bin/mucho-install-auto-update.sh"
fi

install -d -m 700 "$INSTALL_DIR/.muchocore"
cat > "$INSTALL_DIR/.muchocore/profile.env" <<EOFPROFILE
MUCHO_GD_VERSIONS=$GD_VERSIONS
EOFPROFILE
chmod 600 "$INSTALL_DIR/.muchocore/profile.env"

[[ -f "$INSTALL_DIR/docker-compose.yml" ]] || fail "Repository does not contain docker-compose.yml."
[[ -f "$INSTALL_DIR/docker/Dockerfile" ]] || fail "Repository does not contain docker/Dockerfile."
[[ -f "$INSTALL_DIR/docker/Caddyfile" ]] || fail "Repository does not contain docker/Caddyfile."

COMPOSE_ARGS=()
if [[ -n "$TUNNEL_TOKEN" ]]; then
  COMPOSE_ARGS=(-f docker-compose.yml -f docker-compose.tunnel.yml)
fi

INSTALL_STEP="validating Docker Compose"
log "Validating Docker Compose..."
docker compose "${COMPOSE_ARGS[@]}" config -q

INSTALL_STEP="starting production services"
log "Starting MuchoCore..."
cd "$INSTALL_DIR"
if [[ -n "$TUNNEL_TOKEN" ]]; then
  log "Tunnel mode: no inbound ports will be opened; Cloudflare Tunnel provides ingress."
fi
docker compose "${COMPOSE_ARGS[@]}" up -d --build --remove-orphans

log "Verifying running containers..."
expected_services=(db app worker caddy)
if [[ -n "$TUNNEL_TOKEN" ]]; then
  expected_services+=(cloudflared)
fi
for service in "${expected_services[@]}"; do
  container_id="$(docker compose "${COMPOSE_ARGS[@]}" ps -q "$service" 2>/dev/null || true)"
  [[ -n "$container_id" ]] || fail "Service '$service' was not created."
  running="$(docker inspect -f '{{.State.Running}}' "$container_id" 2>/dev/null || true)"
  [[ "$running" == "true" ]] || {
    docker compose "${COMPOSE_ARGS[@]}" ps || true
    docker compose "${COMPOSE_ARGS[@]}" logs --tail=80 "$service" || true
    fail "Service '$service' is not running."
  }
done

INSTALL_STEP="running database migrations"
log "Running database migrations..."
docker compose "${COMPOSE_ARGS[@]}" exec -T app php bin/migrate.php migrate

INSTALL_STEP="running the internal healthcheck"
log "Running internal MuchoCore healthcheck..."
docker compose "${COMPOSE_ARGS[@]}" exec -T app php bin/mucho-healthcheck.php


INSTALL_STEP="checking public health"
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
      warn "Check the tunnel status: cd $INSTALL_DIR && sudo docker compose -f docker-compose.yml -f docker-compose.tunnel.yml logs cloudflared --tail=50"
      warn "Confirm that the tunnel's published application sends traffic to http://caddy:80."
    else
      warn "The server is running, but the domain is not reachable from this VPS yet."
      warn "Check that DNS points to this VPS and that ports 80 and 443 are open."
    fi
  fi
else
  warn "The services started, but the local health check did not pass in time."
  if [[ -n "$TUNNEL_TOKEN" ]]; then
    warn "Run: cd $INSTALL_DIR && sudo docker compose -f docker-compose.yml -f docker-compose.tunnel.yml ps"
  else
    warn "Run: cd $INSTALL_DIR && sudo docker compose ps"
  fi
  if [[ -n "$TUNNEL_TOKEN" ]]; then
    warn "Run: cd $INSTALL_DIR && sudo docker compose -f docker-compose.yml -f docker-compose.tunnel.yml logs --tail=100"
  else
    warn "Run: cd $INSTALL_DIR && sudo docker compose logs --tail=100"
  fi
fi

offer_database_migration() {
  local choice=${MUCHO_MIGRATION_ON_INSTALL:-}

  if [[ -z "$choice" ]]; then
    if [[ ! -t 0 || ! -t 1 ]]; then
      info "Migration prompt skipped because the installer is non-interactive."
      info "You can run the Migration Center later from the admin panel or with: sudo ${INSTALL_DIR}/bin/mucho"
      return 0
    fi

    printf '\n'
    printf "${BOLD}  Do you want to migrate an existing GDPS database now?${RESET}\n"
    printf "  ${CYAN}MuchoCore will run a read-only preflight and, before any import,\n"
    printf "  create and verify a fresh backup of the new MuchoCore database.${RESET}\n\n"
    printf "  ${CYAN}1${RESET}) No — finish installation\n"
    printf "  ${CYAN}2${RESET}) Yes — open Migration Center\n\n"

    read -r -p "  Select [1]: " choice < /dev/tty || choice="1"
    choice="${choice:-1}"
  fi

  case "${choice,,}" in
    1|no|n|false|0)
      info "Database migration skipped. You can start it later from MuchoCore Admin → Tools → Migration Center."
      return 0
      ;;
    2|yes|y|true)
      [[ -f "$INSTALL_DIR/bin/mucho-migrate.php" ]] || {
        warn "Migration Center is not included in this MuchoCore release."
        warn "Finish installation first, then update MuchoCore to a release containing Migration Center."
        return 0
      }

      printf '\n'
      log "Opening MuchoCore Migration Center..."
      info "The old database stays read-only."
      info "Before import, MuchoCore will create and verify a fresh target backup."
      info "If the backup cannot be verified, migration stops before any target write."

      local confirm_args=()
      if [[ -n "${MUCHO_MIGRATION_ON_INSTALL:-}" ]]; then
        confirm_args+=(--confirm=MIGRATE)
      fi

      if [[ -n "$TUNNEL_TOKEN" ]]; then
        if ! docker compose -f docker-compose.yml -f docker-compose.tunnel.yml exec app php bin/mucho-migrate.php --apply "${confirm_args[@]}"; then
          warn "Database migration was not completed. MuchoCore itself is installed; the old database was not modified."
          return 0
        fi
      else
        if ! docker compose exec app php bin/mucho-migrate.php --apply "${confirm_args[@]}"; then
          warn "Database migration was not completed. MuchoCore itself is installed; the old database was not modified."
          return 0
        fi
      fi
      ;;
    *)
      fail "Invalid migration choice. Use 1 (No) or 2 (Yes)."
      ;;
  esac
}

if [[ -n "$MUCHO_MIGRATION_ON_INSTALL" ]]; then
  offer_database_migration
else
  info "Migration skipped. To import an existing GDPS later, use Admin → Tools → Migration Center or: sudo mucho migration"
fi

INSTALL_STEP="completed"
cat <<EOFOUT

MuchoCore is installed.

Compatibility profile:
  GD_VERSIONS=$GD_VERSIONS

GDPS:   https://$DOMAIN
Admin:  https://$DOMAIN/admin/
Health: https://$DOMAIN/health
Path:   $INSTALL_DIR

Admin username: $ADMIN_USER

Update:
  sudo $INSTALL_DIR/update.sh

Operator:
  sudo mucho
  sudo mucho status
  sudo mucho logs
  sudo mucho doctor

Logs:
  sudo mucho logs --follow

Migration:
  sudo mucho migration

EOFOUT
