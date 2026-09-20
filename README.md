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
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install.sh -o install.sh
sudo bash install.sh
```

The installer asks for your domain and asks you to create a password for the admin panel. Database passwords, secrets and the cloud-save key are generated automatically.

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
