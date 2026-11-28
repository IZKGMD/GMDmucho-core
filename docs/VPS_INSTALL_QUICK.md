# Install MuchoCore on an Ubuntu VPS

Use an Ubuntu VPS with a public IPv4 address and SSH access for a user with `sudo` privileges. The installer sets up the PHP 8 core and MariaDB with Docker.

## Install on a new VPS

1. Point your domain's DNS `A` record to the VPS IPv4 address and wait for it to resolve.
2. Allow inbound TCP ports `80` and `443` in your provider's firewall. If UFW is already active on the VPS, the installer opens those ports there.
3. Connect to the VPS over SSH and run:

   ```bash
   curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install-remote.sh | sudo bash
   ```

4. Enter your domain, server name and administrator credentials when prompted. The installer deploys the latest published stable release and creates the owner account.
5. Open `https://YOUR-DOMAIN/admin/` and sign in with the credentials you chose.

## Verify the installation

```bash
curl -fsS https://YOUR-DOMAIN/health
```

The expected response is `1`. Open the [client setup guide](CLIENT_SETUP.md) to connect Geometry Dash. Sign in with a game account, then upload and download a test level.

## Update an existing server

Back up the database, `.env`, `.secrets` and the Cloud Save encryption key before updating.

```bash
sudo mucho update
sudo mucho doctor
```

If the `mucho` command is unavailable, open your installation directory (default `/opt/mucho-core`) and run `sudo ./update.sh`. If the updater reports local Git changes, save a patch or commit them before retrying.

## Troubleshoot

Run `sudo mucho doctor` and check the Docker services if the server fails to start. If HTTPS is unavailable, confirm that DNS points to the VPS and ports `80` and `443` are reachable. See [advanced networking](ADVANCED.md) for tunnel setups.
