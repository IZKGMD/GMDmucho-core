# MuchoCore

MuchoCore is a modern backend core for Geometry Dash Private Servers (GDPS).

**Development version: v1.1.0**

> **New here? Start with [START_HERE.md](START_HERE.md).**
>
> <img src="public/assets/muchocore-mark.svg" alt="MuchoCore" width="72">
>
> You do not need to read the whole repository to get a server running.

## What is included

- accounts and player profiles;
- levels and level lists;
- ratings, comments, and social features;
- Cloud Save;
- an admin panel;
- compatibility with multiple Geometry Dash client generations;
- JSON API v2;
- automated regression and security tests.

## Quick start

| You have | Open |
| --- | --- |
| A Linux VPS with root access | [docs/SETUP.md](docs/SETUP.md) |
| Regular PHP hosting / Beget | [docs/SHARED_HOSTING.md](docs/SHARED_HOSTING.md) |
| A running server and need to connect a client | [docs/CLIENT_SETUP.md](docs/CLIENT_SETUP.md) |
| Something is broken and you do not know what | `bash bin/mucho doctor` |
| You want deeper technical details | [docs/ADVANCED.md](docs/ADVANCED.md) |

### One command to diagnose a problem

When you do not know what is broken, run:

~~~bash
bash bin/mucho doctor
~~~

The doctor checks Docker, configuration, secrets, containers, and `/health`, then tells you what to check next.

## After installation

Check:

~~~text
http://YOUR-DOMAIN/health
~~~

Expected response:

~~~text
1
~~~

Admin panel:

~~~text
http://YOUR-DOMAIN/admin/
~~~

Default administrator username:

~~~text
admin
~~~

The administrator password is chosen during installation.

For Beget/shared hosting, use the ready-made FTP PHP 8.3 package. It already contains `vendor/`, so Composer is not required on the hosting account.

## Connecting Geometry Dash

For Windows, MuchoCore includes:

~~~text
tools/client-patch.bat
~~~

The patcher creates a separate client file and does not overwrite the original EXE.

Important: a successful patcher run only means that known URL strings were replaced successfully. Full compatibility with a specific Geometry Dash build must still be confirmed by testing that client against the server.

## Repository structure

~~~text
START_HERE.md      ← start here if you are new
README.md          ← short project map
src/               ← server logic
public/            ← HTTP entry points and GD endpoints
database/          ← database migrations
tests/             ← automated checks
tools/             ← utilities and patchers
docs/              ← detailed documentation
docker/            ← Docker/Caddy deployment
~~~

### What you usually do not need to touch

As a beginner, you normally do not need to edit:

~~~text
src/
database/
docker/
tests/
~~~

Follow the setup guide first, then check `/health`.

## Security

Never publish:

~~~text
.env
config/cloudsave.key
storage/
.secrets/
~~~

Before removing an installation, read the warning in `uninstall.sh`: it removes containers and the database.

## Developer checks

The main local checks are:

~~~bash
php tests/client-compatibility.php
php tests/router-compatibility.php
php tests/cvolton-compatibility.php
php tests/security-regression.php
php tests/architecture-regression.php
python3 tools/client-patch.py --self-test
bash tests/client-contract.sh
~~~

CI additionally checks shell/PHP/Python code, Docker/Caddy configuration, shared-hosting routing, and the Windows PowerShell patcher.

A real Geometry Dash client still needs to be tested separately before claiming compatibility with a specific build.

## License

MIT. See [LICENSE](LICENSE).
