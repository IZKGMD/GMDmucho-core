# MuchoCore

MuchoCore — a backend for Geometry Dash Private Servers (GDPS).

**Release candidate: v1.0.0**

> **New here? Start with [START_HERE.md](START_HERE.md).**
>
> You do not need to read the entire repository to get the server running.

## What's included

- accounts and profiles;
- levels and level lists;
- ratings, comments, and social features;
- cloud save;
- admin panel;
- compatibility with multiple client generations;
- JSON API v2;
- automated tests;
- MuchoProtect request security and anti-abuse controls.

## Quick guide

| What you have | Open |
| --- | --- |
| Linux VPS with root access | [docs/SETUP.md](docs/SETUP.md) |
| Standard PHP hosting | [docs/SHARED_HOSTING.md](docs/SHARED_HOSTING.md) |
| Server is already running, need the client | [docs/CLIENT_SETUP.md](docs/CLIENT_SETUP.md) |
| Need a deeper technical guide | [docs/ADVANCED.md](docs/ADVANCED.md) |

## After installation

Check:

~~~text
https://YOUR-DOMAIN/health
~~~

Expected response:

~~~text
1
~~~

Admin panel:

~~~text
https://YOUR-DOMAIN/admin/
~~~

Default server user:

~~~text
admin
~~~

The password is set during installation.

## Connecting Geometry Dash

For Windows, use:

~~~text
tools/client-patch.bat
~~~

The patcher creates a separate client file and does not replace the original EXE.

Important: a successful patcher run only confirms that the known URL strings were replaced successfully. Full compatibility with a specific Geometry Dash build can only be confirmed through a real client test.

## Project structure

~~~text
START_HERE.md      ← start here if you are new
README.md          ← project overview
src/               ← server logic
public/            ← HTTP entry points and GD endpoints
database/          ← migrations
tests/             ← tests
tools/             ← tools
docs/              ← detailed documentation
docker/            ← Docker/Caddy
~~~

### What you usually do not need to touch

New users usually do not need to modify:

~~~text
src/
database/
docker/
tests/
~~~

First configure the server using the setup guide, then check /health.

## Security

MuchoCore includes **MuchoProtect**, a centralized request protection layer for GD traffic. It applies conservative per-IP and per-account limits, burst detection, and privacy-preserving security audit events while keeping the Geometry Dash response body compatible.

Never publish:

~~~text
.env
config/cloudsave.key
storage/
.secrets/
~~~

Before removing an installation, read the warning in uninstall.sh: it removes the containers and database.

## Developer checks

The main automated checks are:

~~~bash
php tests/client-compatibility.php
php tests/router-compatibility.php
php tests/client-trace.php
python3 tools/client-patch.py --self-test
bash tests/client-contract.sh
~~~

CI also checks shell/PHP/Python code, Docker/Caddy configuration, shared-hosting routing, client tracing and the Windows PowerShell patcher.

The real Geometry Dash client still needs to be tested separately before making a final client-compatibility claim.

## License

MIT. See [LICENSE](LICENSE).
