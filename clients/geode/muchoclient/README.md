# MuchoClient — one Geode mod for server extensions (v0.4.0)

MuchoClient connects Geometry Dash 2.2081 (Windows and Android) to MuchoCore.
Players install **one .geode file**. Server-side PHP plugins publish safe,
validated feature metadata in MuchoCore's manifest; MuchoClient renders
the extra modules as buttons inside a Geometry Dash popup.

The first demo plugin is at `examples/muchoclient-welcome/`.
The user can click **Welcome** to fetch a read-only JSON message from
`/extensions/welcome`. Only trusted PHP runs on the server — the native
client does not download or execute plugin code.

## Build and use

- GitHub Actions builds Win64, Android ARM64 and ARMv7, and packages them
  in one cross-platform .geode.
- Install through Geode on Geometry Dash 2.2081, then press **Mucho** on the
  game main menu.
- The default server is `https://muchogdps.space`. To use a different server,
  edit the `MuchoCore Server URL` Geode setting (HTTPS origin, without
  `/database`).
- The client GETs `/muchoclient/manifest`, validates the feature catalog, and
  POSTs `/muchoclient/negotiate` with `client_version=0.4.0&protocol=1`.
- Compatible clients display a popup of feature buttons. Public extension
  endpoints under `/extensions/` return constrained JSON text.

## VPS diagnosis

After updating the MuchoCore VPS:

```bash
cd /opt/mucho-core
sudo mucho client-doctor
curl -sS -i https://muchogdps.space/muchoclient/manifest
curl -sS -i https://muchogdps.space/extensions/welcome
```

`sudo mucho client-doctor` probes the PHP application directly inside Docker,
including its database connection, plugin registry, and client handshake.
It does **not** prove that Caddy, Cloudflare, DNS or the public HTTPS endpoint
works. Compare the CLI test with the two HTTPS curl results.

To install the first demo plugin on the server, copy
`examples/muchoclient-welcome` into `custom/plugins/muchoclient-welcome`
on the VPS. The plugin directory must be mounted inside the app container.
See the example's README for detailed steps.

## Security and compatibility

- MuchoClient does not execute remote Lua/JS/PHP/C++ or install new .geode
  binaries. Server responses are data only.
- The feature manifest is public metadata, **not player authentication**.
  Each privileged feature must independently validate the player session.
- Feature paths are limited to trusted relative `/extensions/...` paths.
  Arbitrary external URLs and redirects are rejected.
- The single companion supports GD 2.2081 with Geode, not all legacy GD
  binaries. Standard GD endpoints remain independent of MuchoClient.
- Real-device runtime testing is still required; successful native CI
  compilation does not guarantee a running VPS or installed game behavior.

## Clans v0.4.0

The Clans window uses the current Geometry Dash account ID and GJP2, with
server authorization for every operation. It supports browsing and paged
search, creating open or invite-only clans, joining, leaving, complete member
lists, incoming invitations, and owner/officer management:

- invite by account ID, list outgoing invitations, revoke invitations;
- promote/demote officers, kick members, ban with a reason, list/remove bans;
- edit name, tag, description, member limit and joining mode;
- transfer ownership and disband with explicit confirmation.

Owners cannot leave until ownership is transferred. Officers cannot moderate
owners or other officers. The server rechecks these permissions inside the
mutation transactions. The client preserves failed form input, prevents
concurrent button requests, and cancels callbacks when its popup closes.
All buttons set the game's animation baseline to their intended scale.

Update both the mod and MuchoCore for outgoing invitations and server-paged
search. Existing v0.3 clients remain compatible with the extended JSON API.
Run the clan integration tests against an isolated MariaDB; CI builds the
three native targets and combines them only after those tests pass.

### Validation

```bash
g++ -std=c++20 -Wall -Wextra -Werror tests/client/muchoclient-clan-rules.cpp -o /tmp/clan-rules
/tmp/clan-rules
TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3306 TEST_DB_ROOT_PASSWORD=YOUR_TEST_PASSWORD \
  php tests/application/clan-client-integration.php
```

The integration test creates and drops a randomly named test database. Never
point it at production. Native compilation and these API tests do not replace
verification inside the installed game on Windows and Android.
