#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

bash -n install.sh
bash -n install-remote.sh
bash -n bin/mucho
bash -n bin/mucho-cloudflare-tunnel.sh

grep -Fq -- '--quick' install.sh
grep -Fq -- '--domain=HOST' install.sh
grep -Fq -- '--gd-versions=PROFILE' install.sh
grep -Fq -- '--migrate' install.sh
grep -Fq 'curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install-remote.sh | sudo bash' install.sh
grep -Fq 'check_domain_preflight' install.sh
grep -Fq 'MUCHO_TRANSPORT_MODE' install.sh
grep -Fq 'PUBLIC_IP' install.sh
grep -Fq 'Transport policy: automatic' install.sh
! grep -Fq 'resolve_ref_sha()' install-remote.sh
grep -Fq 'installer_url="${REPO_ROOT}/${SOURCE_REF}/install.sh?cb=${cache_bust}"' install-remote.sh
grep -Fq '< /dev/tty' install.sh
grep -Fq 'Cloudflare credentials (optional)' install.sh
grep -Fq '1) API Token' install.sh
grep -Fq '2) Global API Key' install.sh
grep -Fq '3) Skip' install.sh
grep -Fq 'MUCHO_CLOUDFLARE_GLOBAL_API_KEY' install.sh
grep -Fq 'Account → Cloudflare Tunnel → Edit' install.sh
grep -Fq 'fall back to a Cloudflare Tunnel' install.sh
grep -Fq 'neither direct origin nor Cloudflare Tunnel became healthy' install.sh
grep -Fq 'Installation overview' install.sh
grep -Fq 'Transport policy:' install.sh
grep -Fq 'The installer will stop on a failed public health check' install.sh
grep -Fq 'Automatic transport setup failed: neither direct origin nor Cloudflare Tunnel became healthy' install.sh
grep -Fq 'requires an existing Tunnel runtime token' install.sh
grep -Fq 'https://dash.cloudflare.com/profile/api-tokens' install.sh
grep -Fq 'Account → Cloudflare Tunnel → Edit' install.sh
grep -Fq 'Zone → DNS → Edit' install.sh
grep -Fq 'Zone → Zone → Read' install.sh
grep -Fq 'expected_services=(db app worker caddy)' install.sh
grep -Fq 'MUCHO_CLOUDFLARE_API_TOKEN' install.sh
grep -Fq 'MUCHO_CLOUDFLARE_AUTH_MODE' install.sh
grep -Fq 'MUCHO_CLOUDFLARE_EMAIL' install.sh
grep -Fq 'mucho-cloudflare-tunnel.sh' install.sh
grep -Fq 'bash "$INSTALL_DIR/bin/mucho-cloudflare-tunnel.sh" direct' install.sh
grep -Fq 'configure_direct_origin' bin/mucho-cloudflare-tunnel.sh
grep -Fq 'if [[ "${1:-}" == "direct" ]]; then' bin/mucho-cloudflare-tunnel.sh
grep -Fq 'type:"A"' bin/mucho-cloudflare-tunnel.sh
grep -Fq 'X-Auth-Email: $API_EMAIL' bin/mucho-cloudflare-tunnel.sh
grep -Fq 'X-Auth-Key: $GLOBAL_API_KEY' bin/mucho-cloudflare-tunnel.sh
grep -Fq 'if [[ "$AUTH_MODE" == "token" ]]' bin/mucho-cloudflare-tunnel.sh
grep -Fq 'POST "/accounts/$account_id/cfd_tunnel"' bin/mucho-cloudflare-tunnel.sh
grep -Fq 'PUT "/accounts/$account_id/cfd_tunnel/$tunnel_id/configurations"' bin/mucho-cloudflare-tunnel.sh
grep -Fq 'POST "/zones/$zone_id/dns_records"' bin/mucho-cloudflare-tunnel.sh
grep -Fq 'Cloudflare Tunnel → Edit' README.md
grep -Fq 'sudo mucho doctor' install.sh
grep -Fq -- '--metrics' docker-compose.tunnel.yml
grep -Fq 'TUNNEL_TOKEN_FILE: /run/secrets/muchocore_tunnel_token' docker-compose.tunnel.yml
grep -Fq 'type: bind' docker-compose.tunnel.yml
grep -Fq 'source: ./.secrets/tunnel_token' docker-compose.tunnel.yml
grep -Fq '/run/secrets/muchocore_tunnel_token' docker-compose.tunnel.yml
! grep -Fq 'expected_services=(db app worker caddy testgdps-db testgdps-app)' install.sh
! grep -Fq 'testgdps' docker-compose.yml
grep -Fq 'testgdps' docker-compose.test.yml

grep -Fq 'env_get MUCHO_TRANSPORT_MODE' bin/mucho

for command in status logs restart doctor repair update backup migrate migration config test-stack install test; do
    grep -Fq "${command})" bin/mucho || {
        echo "Missing Mucho CLI command: ${command}" >&2
        exit 1
    }
done

grep -Fq 'docker-compose.test.yml' bin/mucho
grep -Fq 'The production stack never starts the integration test tenant automatically.' bin/mucho

echo "installer-dx: OK"
