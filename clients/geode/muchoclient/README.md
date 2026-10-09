# MuchoClient — Geode companion (developer preview)

MuchoClient is the **single Geode companion** for MuchoCore extension content.
It adds a **Mucho** button to Geometry Dash's main menu and displays the
modules enabled by the GDPS server.

This folder is **source code**, not a tested or compiled .geode download.
It targets Geometry Dash 2.2081 on Windows and Android, following the current
Geode project template. Both native compilation and real-client testing are
still required before shipping a production installer.

## Build and use

1. Install the Geode CLI, SDK and platform-specific C++ toolchain.
2. Set GEODE_SDK to your Geode SDK location.
3. Run geode build from this folder with the matching GD and Geode versions.
4. Install the generated .geode mod through Geode.
5. Set MuchoCore Server URL in the mod settings to your GDPS HTTPS origin.
6. Press **Mucho** on the Geometry Dash main menu.

The client asynchronously requests GET /muchoclient/manifest. It displays
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
