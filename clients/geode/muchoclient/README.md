# MuchoClient — Geode companion (v0.1.1 preview)

MuchoClient is the **single Geode companion** for MuchoCore extension content.
It adds a **Mucho** button to Geometry Dash's main menu and displays the
modules enabled by the GDPS server.

Windows and Android native builds are compiled in GitHub Actions.
Real-device connection and gameplay testing is still required before a production release.

## Build and use

1. Install the Geode CLI, SDK and platform-specific C++ toolchain.
2. Set GEODE_SDK to your Geode SDK location.
3. Run geode build from this folder with the matching GD and Geode versions.
4. Install the generated .geode mod through Geode.
5. By default the client connects to https://muchogdps.space. To use another GDPS, change MuchoCore Server URL in mod settings.
6. Press **Mucho** on the Geometry Dash main menu.

The client first POSTs /muchoclient/negotiate (v0.1.1, protocol 1), then
asynchronously requests GET /muchoclient/manifest when compatible. It displays
module names and descriptions; it does **not** run downloaded scripts, fetch
arbitrary URLs, or grant accounts any new privileges.

## Compatibility contract

- GET /muchoclient/manifest returns core version, required Geode client
  version, API protocol revision, and advertised safe feature metadata.
- POST /muchoclient/negotiate accepts client_version and protocol to determine
  compatibility. It is **not authentication** and returns no access token.
- Features can be contributed by a server PHP plugin with its declared
  client_features permission.
- Existing Geometry Dash 1.0–2.2 standard endpoints are unaffected, even when
  Geode is not installed. Only the extra-client UX requires MuchoClient.
- Geode cannot provide universal injection into every old Geometry Dash
  version; legacy client support remains handled by MuchoCore server protocol.

## Future work

Replace the initial read-only list with real screens for clans, events,
quests and cosmetics, using separate authenticated API endpoints. Treat
server data as untrusted and never allow a module manifest to run C++,
Lua, JS, PHP or install .geode packages without an independently reviewed
and secured distribution system.

## VPS troubleshooting

If the menu shows `-1`, check the application path independently from the
reverse proxy:

```bash
cd /opt/mucho-core
sudo docker compose exec -T app php bin/mucho-client-doctor.php
curl -sS -i https://muchogdps.space/muchoclient/manifest
```

The doctor tests the manifest and negotiation using the installed application
and DB connection. It cannot by itself prove HTTPS/Caddy/Cloudflare works.
