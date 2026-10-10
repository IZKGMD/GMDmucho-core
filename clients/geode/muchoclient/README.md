# MuchoClient — one Geode mod for server extensions (v0.3.0 clan preview)

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
  POSTs `/muchoclient/negotiate` with `client_version=0.3.0&protocol=1`.
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

## Clans v0.3.0

The built-in **Clans** module uses the signed-in Geometry Dash account's ID and
GJP2 token to authenticate with MuchoCore's existing `/api/clans/*` routes.
The mod does **not** save credentials, and every operation is checked by the
MuchoCore server. The client can browse, view, create and join clans; show
members; invite an account by ID; leave a clan (non-owners); and manage
received invitations. The old Welcome server-plugin demo remains intact.

Note: The first clan UI release does not yet implement owner moderation,
clan settings, or ownership transfer in-game. Those server APIs already exist
and can be added in a later update. Real-device regression tests are required.
