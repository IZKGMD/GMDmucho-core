# MuchoCore

MuchoCore is a Geometry Dash private server (GDPS) backend.

## I just want to start the server

You do not need to manually install PHP, MariaDB, Composer or Nginx.

You need only:

1. A Linux VPS with root access.
2. A domain name pointing to the VPS.
3. Open ports 80 and 443.

Then run:

```bash
curl -fsSL https://raw.githubusercontent.com/andrey888787/GMDmucho-core/main/install.sh -o install.sh
sudo bash install.sh
```

The installer asks only for your domain and your admin password. Database passwords, secrets and the cloud-save key are generated automatically.

## After installation

Open:

```text
https://YOUR-DOMAIN/
```

Admin panel:

```text
https://YOUR-DOMAIN/admin/
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
3. Install Python 3.
4. Open the `tools` folder.
5. Double-click `client-patch.bat`.
6. Enter the path to your Geometry Dash executable.
7. Enter your MuchoCore server URL, for example `https://gdps.example.com`.
8. Start the new `*-MuchoCore.exe` file created by the tool.

The tool checks the required URL length before changing the file.

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

- [`docs/CLIENT_SETUP.md`](docs/CLIENT_SETUP.md) — connect the game client.
- [`docs/SETUP.md`](docs/SETUP.md) — server setup and troubleshooting.
- [`docs/openapi.yaml`](docs/openapi.yaml) — JSON API.

## Important files

Keep these safe:

```text
/opt/mucho-core/.secrets/
/opt/mucho-core/.env
/opt/mucho-core/config/cloudsave.key
```

The cloud-save key is required to decrypt existing cloud-save data.

## Included

- Geometry Dash-compatible accounts, levels, scores, comments, social features and cloud saves.
- JSON API v2.
- Admin panel.
- Database migrations.
- Rate limiting and health checks.
- Backup and maintenance helpers.
- Smoke, regression and load tests.
- Docker-based deployment.

## License

This repository is licensed under the MIT License. See [`LICENSE`](LICENSE).

## Client compatibility status

Server-side tests are automated. Real Geometry Dash client compatibility is **not claimed until a real client test is performed**.

See [`docs/CLIENT_TESTING.md`](docs/CLIENT_TESTING.md) for the test and contract workflow.
