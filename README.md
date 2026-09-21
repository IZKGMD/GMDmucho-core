# MuchoCore

MuchoCore is a Geometry Dash private server (GDPS) backend.

## Choose how you host MuchoCore

There are two supported deployment styles.

**Recommended deployment:** VPS is the recommended way to run MuchoCore for a full GDPS deployment. Shared hosting is supported as a simpler, more limited option for testing and smaller deployments.

### I have a VPS

Use the one-command Docker installer:

```bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install.sh -o install.sh
sudo bash install.sh
```

This installs Docker, MariaDB, PHP 8.3, Caddy and MuchoCore automatically.

#### No dedicated public IPv4 (NAT/CGNAT VPS)

Some budget VPS plans only forward SSH and do not allow inbound traffic on
port 80/443 (common on "NAT VPS" plans). If ports 80 and 443 are not
reachable from the internet, Let's Encrypt cannot issue a certificate and
the normal installer will fail its health check.

Use Cloudflare Tunnel instead — it requires no inbound ports at all:

1. Add your domain to Cloudflare (free plan works) and point it at Cloudflare's nameservers.
2. Go to [Cloudflare Zero Trust](https://one.dash.cloudflare.com/) → **Networks → Tunnels → Create a tunnel** (type: Cloudflared).
3. Add a **Public Hostname**: your domain → Service `HTTP` → URL `caddy:80`.
4. Copy the tunnel token shown in the install command (`cloudflared service install <TOKEN>` — you only need the token itself).
5. Run:

```bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install.sh -o install.sh
sudo MUCHO_DOMAIN=your-domain.com MUCHO_TUNNEL_TOKEN=your-tunnel-token bash install.sh
```

No inbound firewall rules are needed on the VPS side — the server connects
out to Cloudflare, which then proxies HTTPS traffic in.

### I have normal shared hosting

Use the browser installer:

1. Upload the complete repository to your hosting account.
2. Install Composer dependencies:

```bash
composer install --no-dev --optimize-autoloader
```

3. Open:

```text
https://YOUR-DOMAIN/shared-install.php
```

4. Follow the green/red checks.
5. Enter your database details and create the admin password.

Full beginner guide: [`docs/SHARED_HOSTING.md`](docs/SHARED_HOSTING.md).

The shared-hosting mode requires PHP 8.3+ and MySQL/MariaDB. It does not use Docker.


For the VPS installer, the script asks for your domain and admin password and generates the database secrets automatically. The shared-hosting browser installer asks for your hosting database details.

The admin panel username is always `admin`.

## After installation

Open:

```text
https://YOUR-DOMAIN/
```

Admin panel:

```text
https://YOUR-DOMAIN/admin/
```

Sign in with:

```text
Username: admin
Password: the admin password you created during installation
```

Health check:

```text
https://YOUR-DOMAIN/health
```

The health check should return `1`.

## Connect the Geometry Dash client

The server and the game client are separate.

The client must use:

```text
https://YOUR-DOMAIN/database
```

For the easiest Windows setup:

1. Download your own Geometry Dash executable.
2. Download this repository.
3. Open the `tools` folder.
4. Double-click `client-patch.bat`.
5. Choose `GeometryDash.exe`.
6. Check the MuchoCore server address.
7. Click `Patch client`.
8. Start the new `*-MuchoCore.exe` file created by the tool.

You do not need Python for the Windows one-click patcher. The patcher handles the fixed-size server URL formats and never overwrites the original executable.

Full client guide: [`docs/CLIENT_SETUP.md`](docs/CLIENT_SETUP.md).

## Update

```bash
sudo /opt/mucho-core/update.sh
```

Your database remains in its Docker volume.

## Remove

Warning: this deletes the MuchoCore database volume.

```bash
sudo /opt/mucho-core/uninstall.sh
```

## Troubleshooting

Check logs:

```bash
cd /opt/mucho-core
sudo docker compose logs -f
```

Check the server:

```bash
curl -i https://YOUR-DOMAIN/health
```

More help:

- [`docs/SHARED_HOSTING.md`](docs/SHARED_HOSTING.md) — shared hosting installation for beginners.
- [`docs/CLIENT_SETUP.md`](docs/CLIENT_SETUP.md) — connect the game client.
- [`docs/SETUP.md`](docs/SETUP.md) — server setup and troubleshooting.
- [`docs/openapi.yaml`](docs/openapi.yaml) — JSON API.

## Important files

Keep these files safe:

```text
.env
config/cloudsave.key
storage/admin-bootstrap.php
```

On VPS installs, the generated secrets also live under:

```text
/opt/mucho-core/.secrets/
```

The cloud-save key must remain unchanged for existing cloud-save data.

## Included

- Geometry Dash-compatible accounts, levels, scores, comments, social features and cloud saves.
- JSON API v2.
- Admin panel.
- Database migrations.
- Rate limiting and health checks.
- Backup and maintenance helpers.
- Smoke, regression and load tests.
- Docker-based VPS deployment.
- Apache/shared-hosting deployment for PHP 8.3+ hosts.

## License

This repository is licensed under the MIT License. See [`LICENSE`](LICENSE).

## Client compatibility status

Server-side tests are automated. Real Geometry Dash client compatibility is **not claimed until a real client test is performed**.

See [`docs/CLIENT_TESTING.md`](docs/CLIENT_TESTING.md) for the test and contract workflow.
