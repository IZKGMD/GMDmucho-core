# MuchoCore Getting Started

This is the shortest path from a fresh VPS to a working Geometry Dash Private Server.

Use the [VPS installation guide](https://muchogdps.space/install/vps/) for the standard direct deployment.

## 1. Point your domain

Create a DNS record pointing your GDPS domain to the VPS:

\`\`\`text
gdps.example.com → YOUR-VPS-IP
\`\`\`

Wait until the DNS record resolves.

## 2. Install MuchoCore

For the normal interactive VPS install, use the stable-release bootstrap:

```bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install-remote.sh | sudo bash
```

The installer asks for only:

- your GDPS domain;
- the initial Admin Panel password.

It installs Docker when needed, deploys the latest **published stable release**, creates the database and Cloud Save secrets, configures Caddy, enables release-based updates, runs migrations, and verifies the production health endpoint.

For unattended or explicit profiles, pass the options through the remote bootstrap:

```bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install-remote.sh | sudo bash -s -- --domain=gdps.example.com --gd-versions=22
```

Use `--gd-versions=all` for all supported generations. Existing installations can change the profile later with:

```bash
sudo mucho config
```

The normal production install does **not** start the integration-test tenant. To start that optional stack:

```bash
sudo mucho test-stack up -d
```

## 3. Check health

Open:

\`\`\`text
https://YOUR-DOMAIN/health
\`\`\`

Expected:

\`\`\`text
1
\`\`\`

Open the Admin Panel:

\`\`\`text
https://YOUR-DOMAIN/admin/
\`\`\`

The default administrator username is:

\`\`\`text
admin
\`\`\`

The password is created during installation.

## 4. Patch the client

Open the Admin Panel and use:

\`\`\`text
/admin/?page=clientpatcher
\`\`\`

Or use the local Windows patcher documented in [Client Setup](CLIENT_SETUP.md).

The Android patcher produces an unsigned APK. Sign it with your own Android signing key before distribution.

## 5. Migrate an existing GDPS

Already running a Cvolton/GMDprivateServer-style GDPS?

Start with the [Migration Kit](MIGRATION_KIT.md).

The safe flow is:

\`\`\`text
Existing GDPS
    ↓
Dry-run / preflight
    ↓
Review source counts
    ↓
Verified target backup
    ↓
Transactional import
    ↓
Healthcheck
    ↓
Test the migrated client
\`\`\`

Dry-run:

\`\`\`bash
sudo ./tools/migration/mucho-migrate.sh \\
  --source-host=SOURCE_DB_HOST \\
  --source-db=SOURCE_DB_NAME \\
  --source-user=SOURCE_DB_USER
\`\`\`

Apply:

\`\`\`bash
sudo ./tools/migration/mucho-migrate.sh \\
  --source-host=SOURCE_DB_HOST \\
  --source-db=SOURCE_DB_NAME \\
  --source-user=SOURCE_DB_USER \\
  --apply --confirm=COVOLTON
\`\`\`

Never paste a production database password into the command line. Set \`CVOLTON_SOURCE_PASS\` or use the secure prompt.

## 6. Update

\`\`\`bash
sudo /opt/mucho-core/update.sh
\`\`\`

Production updates follow published stable GitHub Releases.

## 7. Backup

Before major maintenance:

\`\`\`bash
sudo /opt/mucho-core/bin/mucho-db-backup.sh
\`\`\`

## 8. Where to go next

- [Full VPS setup](SETUP.md)
- [Client setup](CLIENT_SETUP.md)
- [Cvolton migration details](CVOLTON_MIGRATION.md)
- [Migration Kit](MIGRATION_KIT.md)
- [Version profiles](VERSIONS.md)
- [Custom plugins](CUSTOM_PLUGINS.md)
