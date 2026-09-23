# Advanced Scenarios

This page is not required for a normal installation.

## NAT / CGNAT VPS

If the VPS does not accept inbound connections on ports 80 and 443, the normal HTTPS setup cannot work directly.

One option is Cloudflare Tunnel. It creates an outbound connection from the server to Cloudflare, so no inbound port forwarding to the VPS is required.

### MuchoCore installer flow

1. Open **Networking → Tunnels** in the Cloudflare Dashboard.
2. Create or select a cloudflared Tunnel.
3. Create a Published Application for your domain.
4. Set the origin to:

```text
http://caddy:80
```

5. Copy the connector token.
6. Pass it to the installer:

```bash
export MUCHO_TUNNEL_TOKEN='YOUR_TUNNEL_TOKEN'
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install.sh -o install.sh
sudo -E bash install.sh
```

In tunnel mode MuchoCore switches Caddy to plain HTTP inside Docker and runs cloudflared as a separate container. TLS terminates at Cloudflare.

**Never publish the connector token.** It is a Tunnel credential.

## Existing Tunnel

For an existing Tunnel, use its connector setup page. Cloudflare may show a Linux installation flow instead of the older "Add a replica" UI.

For a normal public VPS, a Tunnel is not required. Use `docs/SETUP.md`.

## Shared hosting without Docker

Shared hosting is a separate deployment mode. It uses PHP 8.3+, MySQL/MariaDB and Apache or a compatible web server.

See `docs/SHARED_HOSTING.md`.

## API v2

JSON API v2 is intended for programs, admin tools and integrations. Normal GDPS operation does not require it.

Specification:

`openapi.yaml`

## Real client compatibility

Automated tests validate the server code and known routes. They do not replace testing a real Geometry Dash client.

For a new client version, use `CLIENT_TESTING.md`.

## Developer structure

```text
src/        core logic
public/     HTTP entry points
            database/ — legacy GD endpoints
            api/v2/   — JSON API
            admin/    — web admin
database/   migrations
tests/      automated checks
tools/      developer tools
docker/     containers and Caddy
```

New users normally do not need to edit source code.

## Core rule

Start with the simple instructions in `START_HERE.md`. Change advanced settings only when you understand the problem they solve.
