# MuchoCore — Start Here

MuchoCore is a modern Geometry Dash Private Server core for owners who want a maintainable backend, version-aware compatibility and production tooling without assembling every component by hand.

## New to MuchoCore?

For a normal PHP shared host without Docker, use **[docs/SHARED_HOSTING.md](docs/SHARED_HOSTING.md)** and the `MuchoCore-vX.Y.Z-shared-hosting.zip` release asset. It includes Composer dependencies and the browser installer, so no server terminal is required.

Use the shortest installation path:

~~~bash
git clone https://github.com/IZKGMD/GMDmucho-core.git
cd GMDmucho-core
sudo ./install
~~~

Or read **[docs/GETTING_STARTED.md](docs/GETTING_STARTED.md)** for the complete first-run path.

## Already running a Cvolton/GMDprivateServer-style GDPS?

Start with **[docs/MIGRATION_KIT.md](docs/MIGRATION_KIT.md)**.

The safe migration flow is:

~~~text
Source GDPS
    ↓
Read-only preflight
    ↓
Review source counts
    ↓
Verified MuchoCore backup
    ↓
Transactional import
    ↓
Post-migration healthcheck
    ↓
Test client
~~~

Dry-run is the default. Nothing is imported until `--apply --confirm=COVOLTON` is supplied.

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

The password is created during installation.

## Compatibility

The documented runtime profiles cover:

~~~text
GD 1.0
GD 1.1
GD 1.5
GD 1.9
GD 2.0
GD 2.1
GD 2.2
Custom combinations
~~~

See **[docs/VERSIONS.md](docs/VERSIONS.md)** and **[docs/CLIENT_COMPATIBILITY.md](docs/CLIENT_COMPATIBILITY.md)**.

## Connect a client

After the server is healthy, use **[docs/CLIENT_SETUP.md](docs/CLIENT_SETUP.md)**.

Windows patching tools live under `tools/client/`; Android patching is available through the Admin Panel workflow.

## Main directories

~~~text
src/       — server logic
public/    — HTTP entry points, GD endpoints and Admin Panel
database/  — database migrations
config/    — local configuration and examples
storage/   — runtime data
tests/     — automated validation and compatibility checks
tools/     — client and migration tools
docs/      — setup, migration, compatibility and deployment documentation
docker/    — Docker and Caddy files
custom/    — persistent GDPS-specific extensions
~~~

## Security

Do not publish or commit:

~~~text
.env
.secrets/
config/cloudsave.key
storage/
~~~

Before major maintenance, create a database backup. Do not run `uninstall.sh` unless you understand that the database volume will be removed.
