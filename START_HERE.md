# MuchoCore — Start Here

<img src="public/assets/muchocore-mark.svg" alt="MuchoCore" width="84">

Welcome! MuchoCore can be deployed without deep PHP or Docker knowledge.

## What is MuchoCore?

MuchoCore is the server-side core for a Geometry Dash Private Server (GDPS).

Think of it like this:

```text
Geometry Dash
     ↓
MuchoCore
     ↓
MySQL / MariaDB
```

## I am a beginner

Choose one of these two paths:

### 1. VPS

This is the recommended setup for a full GDPS.

Start with:

```text
docs/SETUP.md
```

The simplest installation is:

```bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install.sh -o install.sh
sudo bash install.sh
```

The installer sets up the containers, database, HTTPS, and administrator account.

### 2. Regular PHP hosting / Beget

This is useful for a simple or test server and can be installed without SSH.

Start with:

```text
docs/SHARED_HOSTING.md
```

Use the ready-made FTP PHP 8.3 package. Upload it by FTP, create an empty MySQL/MariaDB database, then open `/shared-install.php` in your browser.

## When something does not work

Do not search through the whole repository.

Run:

```bash
bash bin/mucho doctor
```

Then look at the lines marked `FAIL` and `WARN`.

Useful commands:

```bash
bash bin/mucho status
bash bin/mucho logs
bash bin/mucho health
```

## After installation

Check:

```text
https://YOUR-DOMAIN/health
```

A healthy server should return:

```text
1
```

Then open:

```text
https://YOUR-DOMAIN/admin/
```

Default administrator username:

```text
admin
```

Use the administrator password you created during installation.

## Connect Geometry Dash

After the server responds to `/health`, continue with:

```text
docs/CLIENT_SETUP.md
```

For Windows, MuchoCore includes:

```text
tools/client-patch.bat
```

The patcher creates a separate client file and does not replace the original EXE.

## Repository structure

```text
src/               ← server logic
public/            ← HTTP entry points and GD endpoints
database/          ← database migrations
config/             ← local configuration and keys
storage/            ← runtime data and service files
tests/              ← automated checks
tools/              ← utilities and patchers
docs/               ← detailed documentation
docker/             ← Docker/Caddy deployment
```

You do not need to understand every directory.

## Important files

Do not publish or share:

```text
.env
config/cloudsave.key
storage/
.secrets/
```

Do not run `uninstall.sh` unless you understand that it removes the installation and database.

## Simple troubleshooting

Check these in order:

1. Open `/health`.
2. Run `bash bin/mucho doctor`.
3. Read the latest server error from `bash bin/mucho logs`.
4. Check the matching guide in `docs/`.

Change one thing at a time and check `/health` again after each change.

## Developer workflow

For code changes, run:

```bash
composer install
composer compat
```

Then run the full project test suite before opening a pull request.
