#!/usr/bin/env bash
set -Eeuo pipefail

# Configure a remotely-managed Cloudflare Tunnel for MuchoCore.
# The Cloudflare API token is read from MUCHO_CLOUDFLARE_API_TOKEN and is
# intentionally not persisted. Only the tunnel runtime token is stored.

API_BASE="https://api.cloudflare.com/client/v4"
INSTALL_DIR="${MUCHO_INSTALL_DIR:-/opt/mucho-core}"
DOMAIN="${MUCHO_DOMAIN:-}"
AUTH_MODE="${MUCHO_CLOUDFLARE_AUTH_MODE:-token}"
API_TOKEN="${MUCHO_CLOUDFLARE_API_TOKEN:-}"
API_EMAIL="${MUCHO_CLOUDFLARE_EMAIL:-}"
GLOBAL_API_KEY="${MUCHO_CLOUDFLARE_GLOBAL_API_KEY:-}"
# Normalize common clipboard forms such as "Bearer <token>".
API_TOKEN="$(printf '%s' "$API_TOKEN" | sed 's/^Bearer[[:space:]]*//I; s/^[[:space:]]*//; s/[[:space:]]*$//')"
GLOBAL_API_KEY="$(printf '%s' "$GLOBAL_API_KEY" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
TUNNEL_NAME="${MUCHO_CLOUDFLARE_TUNNEL_NAME:-}"

if [[ -z "$AUTH_MODE" ]]; then
  if [[ -n "$API_TOKEN" ]]; then
    AUTH_MODE="token"
  elif [[ -n "$GLOBAL_API_KEY" ]]; then
    AUTH_MODE="global-key"
  fi
fi

die() { printf '[Cloudflare] ERROR: %s\n' "$*" >&2; exit 1; }
info() { printf '[Cloudflare] %s\n' "$*" >&2; }

command -v curl >/dev/null 2>&1 || die "curl is required."
command -v jq >/dev/null 2>&1 || die "jq is required."
command -v openssl >/dev/null 2>&1 || die "openssl is required."
[[ -n "$DOMAIN" ]] || die "MUCHO_DOMAIN is required."
[[ -f "$INSTALL_DIR/.env" ]] || die "MuchoCore .env was not found at $INSTALL_DIR."
case "$AUTH_MODE" in
  token)
    [[ -n "$API_TOKEN" ]] || die "MUCHO_CLOUDFLARE_API_TOKEN is required."
    ;;
  global-key)
    [[ -n "$API_EMAIL" ]] || die "MUCHO_CLOUDFLARE_EMAIL is required for Global API Key authentication."
    [[ -n "$GLOBAL_API_KEY" ]] || die "MUCHO_CLOUDFLARE_GLOBAL_API_KEY is required."
    ;;
  *)
    die "Unsupported Cloudflare auth mode: $AUTH_MODE (use token or global-key)."
    ;;
esac

cf_request() {
  local method="$1"
  local path="$2"
  local body="${3:-}"
  local response
  local http_code
  local -a headers=(
    -H 'Accept: application/json'
    -H 'User-Agent: MuchoCore-Installer/1.0'
  )

  if [[ "$AUTH_MODE" == "token" ]]; then
    headers+=(-H "Authorization: Bearer $API_TOKEN")
  else
    headers+=(-H "X-Auth-Email: $API_EMAIL" -H "X-Auth-Key: $GLOBAL_API_KEY")
  fi

  if [[ -n "$body" ]]; then
    response="$(curl -4sS --retry 3 --retry-delay 1       --connect-timeout 5 --max-time 30       -X "$method" "${headers[@]}"       -H 'Content-Type: application/json'       -w '\n__HTTP_STATUS__:%{http_code}'       --data "$body" "$API_BASE$path")" || die "Cloudflare API request failed: $method $path"
  else
    response="$(curl -4sS --retry 3 --retry-delay 1       --connect-timeout 5 --max-time 30       -X "$method" "${headers[@]}"       -w '\n__HTTP_STATUS__:%{http_code}'       "$API_BASE$path")" || die "Cloudflare API request failed: $method $path"
  fi

  http_code="$(printf '%s\n' "$response" | sed -n 's/^__HTTP_STATUS__://p' | tail -n1)"
  response="$(printf '%s\n' "$response" | sed '/^__HTTP_STATUS__:/d')"

  if [[ "$http_code" != 2* ]]; then
    local message
    message="$(printf '%s' "$response" | jq -r '[.errors[]?.message] | join("; ")' 2>/dev/null || true)"
    [[ -n "$message" ]] || message="HTTP $http_code"
    die "Cloudflare API rejected $method $path: $message"
  fi

  if ! printf '%s' "$response" | jq -e '.success == true' >/dev/null 2>&1; then
    local message
    message="$(printf '%s' "$response" | jq -r '[.errors[]?.message] | join("; ")' 2>/dev/null || true)"
    [[ -n "$message" ]] || message="Unknown Cloudflare API error"
    die "Cloudflare API rejected $method $path: $message"
  fi

  printf '%s' "$response"
}

find_zone() {
  local candidate="$DOMAIN"
  local response zone_id account_id zone_name

  while [[ "$candidate" == *.* ]]; do
    response="$(cf_request GET "/zones?name=$(printf '%s' "$candidate" | jq -sRr @uri)&per_page=20")"
    zone_id="$(printf '%s' "$response" | jq -r --arg n "$candidate" '.result[]? | select(.name == $n and .status == "active") | .id' | head -n1)"
    zone_name="$(printf '%s' "$response" | jq -r --arg n "$candidate" '.result[]? | select(.name == $n and .status == "active") | .name' | head -n1)"
    account_id="$(printf '%s' "$response" | jq -r --arg n "$candidate" '.result[]? | select(.name == $n and .status == "active") | .account.id' | head -n1)"

    if [[ -n "$zone_id" && "$zone_id" != "null" && -n "$account_id" && "$account_id" != "null" ]]; then
      printf '%s\t%s\t%s\n' "$zone_id" "$account_id" "$zone_name"
      return 0
    fi
    candidate="${candidate#*.}"
  done

  return 1
}

ensure_tunnel() {
  local account_id="$1"
  local tunnel
  local tunnel_id
  local tunnel_secret
  local create_body
  local list_response

  if [[ -n "$TUNNEL_NAME" ]]; then
    tunnel_name="$TUNNEL_NAME"
  else
    local suffix
    suffix="$(printf '%s' "$DOMAIN" | sha256sum | awk '{print substr($1,1,8)}')"
    tunnel_name="muchocore-${DOMAIN:0:80}-${suffix}"
  fi

  list_response="$(cf_request GET "/accounts/$account_id/cfd_tunnel?per_page=100")"
  tunnel_id="$(printf '%s' "$list_response" | jq -r --arg n "$tunnel_name" '.result[]? | select(.name == $n and .config_src == "cloudflare" and (.deleted_at == null or .deleted_at == "")) | .id' | head -n1)"

  if [[ -n "$tunnel_id" && "$tunnel_id" != "null" ]]; then
    info "Reusing existing Cloudflare Tunnel: $tunnel_name"
  else
    tunnel_secret="$(openssl rand -base64 32 | tr -d '\n')"
    create_body="$(jq -cn --arg name "$tunnel_name" --arg secret "$tunnel_secret" '{name:$name,config_src:"cloudflare",tunnel_secret:$secret}')"
    tunnel="$(cf_request POST "/accounts/$account_id/cfd_tunnel" "$create_body")"
    tunnel_id="$(printf '%s' "$tunnel" | jq -r '.result.id // empty')"
    [[ -n "$tunnel_id" ]] || die "Cloudflare created a Tunnel but returned no tunnel ID."
    info "Created Cloudflare Tunnel: $tunnel_name"
  fi

  local runtime_token
  runtime_token="$(cf_request GET "/accounts/$account_id/cfd_tunnel/$tunnel_id/token" | jq -r '.result // empty')"
  [[ -n "$runtime_token" ]] || die "Cloudflare returned no connector token for Tunnel $tunnel_id."

  printf '%s\n' "$tunnel_id"
  printf '%s\n' "$runtime_token"
  printf '%s\n' "$tunnel_name"
}

configure_direct_dns() {
  local zone_id="$1"
  local origin_ip="$2"
  local host="$3"
  local records record_id record_type body kept=0

  [[ "$origin_ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] ||
    die "Invalid direct origin IPv4: $origin_ip"

  records="$(cf_request GET "/zones/$zone_id/dns_records?name.exact=$(printf '%s' "$host" | jq -sRr @uri)&per_page=100")"

  while IFS=$'\t' read -r record_id record_type; do
    [[ -n "$record_id" ]] || continue

    case "$record_type" in
      A)
        if [[ "$kept" -eq 0 ]]; then
          body="$(jq -cn --arg name "$host" --arg content "$origin_ip" \
            '{name:$name,type:"A",ttl:1,content:$content,proxied:false,comment:"Managed by MuchoCore (DNS-only direct origin)"}')"
          cf_request PATCH "/zones/$zone_id/dns_records/$record_id" "$body" >/dev/null
          kept=1
          info "Updated direct DNS A: $host → $origin_ip"
        else
          cf_request DELETE "/zones/$zone_id/dns_records/$record_id" >/dev/null
          info "Removed duplicate A record for $host"
        fi
        ;;
      AAAA|CNAME)
        cf_request DELETE "/zones/$zone_id/dns_records/$record_id" >/dev/null
        info "Removed conflicting $record_type record for $host"
        ;;
    esac
  done < <(printf '%s' "$records" | jq -r '.result[]? | [.id,.type] | @tsv')

  if [[ "$kept" -eq 0 ]]; then
    body="$(jq -cn --arg name "$host" --arg content "$origin_ip" \
      '{name:$name,type:"A",ttl:1,content:$content,proxied:false,comment:"Managed by MuchoCore (DNS-only direct origin)"}')"
    cf_request POST "/zones/$zone_id/dns_records" "$body" >/dev/null
    info "Created direct DNS A: $host → $origin_ip"
  fi
}

configure_direct_origin() {
  local zone_id="$1"
  local origin_ip="$2"

  configure_direct_dns "$zone_id" "$origin_ip" "$DOMAIN"
  if [[ "$DOMAIN" != www.* ]]; then
    configure_direct_dns "$zone_id" "$origin_ip" "www.$DOMAIN"
  fi

  info "Cloudflare DNS is configured for direct origin access (DNS-only, no Cloudflare proxy)."
}

configure_tunnel() {
  local account_id="$1"
  local tunnel_id="$2"
  local config_body

  config_body="$(jq -cn     --arg host "$DOMAIN"     --arg www "www.$DOMAIN"     '{
      config: {
        ingress: [
          {hostname:$host, service:"http://caddy:80"},
          {hostname:$www, service:"http://caddy:80"},
          {service:"http_status:404"}
        ]
      }
    }')"

  cf_request PUT "/accounts/$account_id/cfd_tunnel/$tunnel_id/configurations" "$config_body" >/dev/null
  info "Configured Tunnel ingress: $DOMAIN, www.$DOMAIN → http://caddy:80"
}

upsert_dns() {
  local zone_id="$1"
  local host="$2"
  local tunnel_target="$3"
  local records record_count
  local record_id record_type
  local body

  records="$(cf_request GET "/zones/$zone_id/dns_records?name.exact=$(printf '%s' "$host" | jq -sRr @uri)&per_page=100")"
  record_count="$(printf '%s' "$records" | jq '.result | length')"

  if [[ "$record_count" -gt 0 ]]; then
    while IFS=$'\t' read -r record_id record_type; do
      [[ -n "$record_id" ]] || continue
      case "$record_type" in
        CNAME)
          body="$(jq -cn --arg name "$host" --arg content "$tunnel_target" '{name:$name,type:"CNAME",ttl:1,content:$content,proxied:true,comment:"Managed by MuchoCore"}')"
          cf_request PATCH "/zones/$zone_id/dns_records/$record_id" "$body" >/dev/null
          info "Updated DNS CNAME: $host → $tunnel_target"
          return 0
          ;;
        A|AAAA)
          cf_request DELETE "/zones/$zone_id/dns_records/$record_id" >/dev/null
          info "Removed conflicting $record_type record for $host"
          ;;
      esac
    done < <(printf '%s' "$records" | jq -r '.result[]? | [.id,.type] | @tsv')
  fi

  body="$(jq -cn --arg name "$host" --arg content "$tunnel_target" '{name:$name,type:"CNAME",ttl:1,content:$content,proxied:true,comment:"Managed by MuchoCore"}')"
  cf_request POST "/zones/$zone_id/dns_records" "$body" >/dev/null
  info "Created DNS CNAME: $host → $tunnel_target"
}

verify_credentials() {
  if [[ "$AUTH_MODE" == "token" ]]; then
    local response status
    response="$(cf_request GET "/user/tokens/verify")"
    status="$(printf '%s' "$response" | jq -r '.result.status // empty')"
    [[ "$status" == "active" ]] || die "Cloudflare API token is not active (status: ${status:-unknown})."
    info "Cloudflare API token verified."
  else
    info "Cloudflare Global API Key authentication selected."
  fi
}

verify_credentials

read -r ZONE_ID ACCOUNT_ID ZONE_NAME < <(find_zone) || die "Could not find an active Cloudflare zone for $DOMAIN. Make sure the domain is on this Cloudflare account and the API token has Zone Read."

[[ -n "$ZONE_ID" && -n "$ACCOUNT_ID" ]] || die "Cloudflare zone/account lookup returned incomplete data."

info "Using Cloudflare zone: $ZONE_NAME"
info "Using Cloudflare account: $ACCOUNT_ID"

if [[ "${1:-}" == "direct" ]]; then
  ORIGIN_IP="${MUCHO_PUBLIC_IP:-}"
  [[ -n "$ORIGIN_IP" ]] || ORIGIN_IP="$(curl -4fsS --connect-timeout 5 --max-time 10 https://api.ipify.org 2>/dev/null || true)"
  [[ -n "$ORIGIN_IP" ]] || die "Could not determine the public origin IPv4 address."
  configure_direct_origin "$ZONE_ID" "$ORIGIN_IP"
  exit 0
fi

mapfile -t tunnel_info < <(ensure_tunnel "$ACCOUNT_ID")
TUNNEL_ID="${tunnel_info[0]:-}"
TUNNEL_RUNTIME_TOKEN="${tunnel_info[1]:-}"
TUNNEL_NAME_EFFECTIVE="${tunnel_info[2]:-}"
[[ -n "$TUNNEL_ID" && -n "$TUNNEL_RUNTIME_TOKEN" ]] || die "Failed to prepare the Cloudflare Tunnel."

configure_tunnel "$ACCOUNT_ID" "$TUNNEL_ID"

TUNNEL_TARGET="$TUNNEL_ID.cfargotunnel.com"
upsert_dns "$ZONE_ID" "$DOMAIN" "$TUNNEL_TARGET"
if [[ "$DOMAIN" != www.* ]]; then
  upsert_dns "$ZONE_ID" "www.$DOMAIN" "$TUNNEL_TARGET"
fi

printf '%s\n' "$TUNNEL_RUNTIME_TOKEN" > "$INSTALL_DIR/.secrets/tunnel_token"
chown 65532:65532 "$INSTALL_DIR/.secrets/tunnel_token"
chmod 400 "$INSTALL_DIR/.secrets/tunnel_token"

if grep -q '^MUCHO_TUNNEL_TOKEN=' "$INSTALL_DIR/.env"; then
  sed -i "s|^MUCHO_TUNNEL_TOKEN=.*|MUCHO_TUNNEL_TOKEN=$TUNNEL_RUNTIME_TOKEN|" "$INSTALL_DIR/.env"
else
  printf 'MUCHO_TUNNEL_TOKEN=%s\n' "$TUNNEL_RUNTIME_TOKEN" >> "$INSTALL_DIR/.env"
fi
if grep -q '^MUCHO_CLOUDFLARE_ACCOUNT_ID=' "$INSTALL_DIR/.env"; then
  sed -i "s|^MUCHO_CLOUDFLARE_ACCOUNT_ID=.*|MUCHO_CLOUDFLARE_ACCOUNT_ID=$ACCOUNT_ID|" "$INSTALL_DIR/.env"
else
  printf 'MUCHO_CLOUDFLARE_ACCOUNT_ID=%s\n' "$ACCOUNT_ID" >> "$INSTALL_DIR/.env"
fi
if grep -q '^MUCHO_CLOUDFLARE_ZONE_ID=' "$INSTALL_DIR/.env"; then
  sed -i "s|^MUCHO_CLOUDFLARE_ZONE_ID=.*|MUCHO_CLOUDFLARE_ZONE_ID=$ZONE_ID|" "$INSTALL_DIR/.env"
else
  printf 'MUCHO_CLOUDFLARE_ZONE_ID=%s\n' "$ZONE_ID" >> "$INSTALL_DIR/.env"
fi
if grep -q '^MUCHO_CLOUDFLARE_TUNNEL_ID=' "$INSTALL_DIR/.env"; then
  sed -i "s|^MUCHO_CLOUDFLARE_TUNNEL_ID=.*|MUCHO_CLOUDFLARE_TUNNEL_ID=$TUNNEL_ID|" "$INSTALL_DIR/.env"
else
  printf 'MUCHO_CLOUDFLARE_TUNNEL_ID=%s\n' "$TUNNEL_ID" >> "$INSTALL_DIR/.env"
fi
if grep -q '^CADDY_ADDRESS_VALUE=' "$INSTALL_DIR/.env"; then
  sed -i 's|^CADDY_ADDRESS_VALUE=.*|CADDY_ADDRESS_VALUE=":80"|' "$INSTALL_DIR/.env"
else
  printf 'CADDY_ADDRESS_VALUE=":80"\n' >> "$INSTALL_DIR/.env"
fi
if grep -q '^CADDY_ADDRESS=' "$INSTALL_DIR/.env"; then
  sed -i 's|^CADDY_ADDRESS=.*|CADDY_ADDRESS=":80"|' "$INSTALL_DIR/.env"
else
  printf 'CADDY_ADDRESS=":80"\n' >> "$INSTALL_DIR/.env"
fi

info "Cloudflare Tunnel is ready: $TUNNEL_NAME_EFFECTIVE"
info "Cloudflare API credentials were used only for setup and were not persisted."
