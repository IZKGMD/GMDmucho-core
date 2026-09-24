# MuchoCore — Start Here

MuchoCore can be deployed without deep PHP or Docker knowledge.

## What it is

MuchoCore is a backend for Geometry Dash Private Servers.

~~~text
Geometry Dash
     ↓
MuchoCore
     ↓
MariaDB
~~~

## New to MuchoCore?

For a normal VPS deployment, start with:

~~~bash
sudo ./install
~~~

The installer guides you through the domain, administrator password and Geometry Dash compatibility profile.

Supported profiles:

~~~text
GD 1.9
GD 2.0
GD 2.1
GD 2.2
Any supported combination
~~~

See `docs/VERSIONS.md` for how the version profiles work.

## After installation

Check:

~~~text
https://YOUR-DOMAIN/health
~~~

Expected response:

~~~text
1
~~~

Then open:

~~~text
https://YOUR-DOMAIN/admin/
~~~

Administrator username:

~~~text
admin
~~~

The password is the one created during installation.

## Connect a client

After the server is healthy, use:

~~~text
docs/CLIENT_SETUP.md
~~~

For Windows clients, the repository includes a client patcher under `tools/client/`.

## Main directories

~~~text
src/       — server logic
public/    — HTTP entry points and GD endpoints
database/  — database migrations
config/    — local configuration and keys
storage/   — runtime data and service files
tests/     — automated tests
tools/     — client and development tools
docs/      — documentation
docker/    — Docker and Caddy files
~~~

## Security

Do not publish or commit:

~~~text
.env
config/cloudsave.key
storage/
.secrets/
~~~

Do not run `uninstall.sh` unless you understand that the database volume will be removed.
