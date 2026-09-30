#!/usr/bin/env bash
set -Eeuo pipefail

# Configure a remotely-managed Cloudflare Tunnel for MuchoCore.
# The Cloudflare API token is read from MUCHO_CLOUDFLARE_API_TOKEN and is
# intentionally not persisted. Only the tunnel runtime token is stored.

API_BASE="https://api.cloudflare.com/client/v4"
INSTALL_DIR="${MUCHO_INSTALL_DIR:-/opt/mucho-core}"
DOMAIN="${MUCHO_DOMAIN:-}"
API_TOKEN="${MUCHO_CLOUDFLARE_API_TOKEN:-}"
TUNNEL_NAME="${MUCHO_CLOUDFLARE_TUNNEL_NAME:-}"

die() { printf '[Cloudflare] ERROR: %s\n' "$*" >&2; exit 1; }
info() { printf '[Cloudflare] %s\n' "$*"; }

command -v curl >/dev/null 2>&1 || die "curl is required."
command -v jq >/dev/null 2>&1 || die "jq is required."
command -v openssl >/dev/null 2>&1 || die "openssl is required."
[[ -n "$DOMAIN" ]] || die "MUCHO_DOMAIN is required."
[[ -n "$API_TOKEN" ]] || die "MUCHO_CLOUDFLARE_API_TOKEN is required."
[[ -f "$INSTALL_DIR/.env" ]] || die "MuchoCore .env was not found at $INSTALL_DIR."

cf_request() {
  local method="$1"
  local path="$2"
  local body="${3:-}"
  local response
  local http_code

  if [[ -n "$body" ]]; then
    response="$(curl -4sS --retry 3 --retry-delay 1       --connect-timeout 5 --max-time 30       -X "$method"       -H "Authorization: Bearer $API_TOKEN"       -H 'Content-Type: application/json'       -H 'Accept: application/json'       -w '\n__HTTP_STATUS__:%{http_code}'       --data "$body"       "$API_BASE$path")" || die "Cloudflare API request failed: $method $path"
  else
    response="$(curl -4sS --retry 3 --retry-delay 1       --connect-timeout 5 --max-time 30       -X "$method"       -H "Authorization: Bearer $API_TOKEN"       -H 'Accept: application/json'       -w '\n__HTTP_STATUS__:%{http_code}'       "$API_BASE$path")" || die "Cloudflare API request failed: $method $path"
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
  tunnel_id="$(printf '%s' "$list_response" | jq -r --arg n "$tunnel_name" '.result[]? | select(.name == $n and (.deleted_at == null or .deleted_at == "")) | .id' | head -n1)"

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

read -r ZONE_ID ACCOUNT_ID ZONE_NAME < <(find_zone) || die "Could not find an active Cloudflare zone for $DOMAIN. Make sure the domain is on this Cloudflare account and the API token has Zone Read."

[[ -n "$ZONE_ID" && -n "$ACCOUNT_ID" ]] || die "Cloudflare zone/account lookup returned incomplete data."

info "Using Cloudflare zone: $ZONE_NAME"
info "Using Cloudflare account: $ACCOUNT_ID"

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
chmod 600 "$INSTALL_DIR/.secrets/tunnel_token"

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
info "The API token was used only for setup and was not persisted."
