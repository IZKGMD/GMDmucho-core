# MuchoClient Welcome — first live server extension

This is a demonstration **trusted PHP server plugin**. It adds a **Welcome**
button to the single MuchoClient .geode mod. Clicking the button fetches
read-only JSON from MuchoCore at /extensions/welcome and opens it in GD.

## Installation on MuchoCore VPS

After updating MuchoCore to a commit containing the MuchoClient bridge:

    cd /opt/mucho-core
    mkdir -p custom/plugins
    cp -a examples/muchoclient-welcome custom/plugins/muchoclient-welcome
    curl -fsS https://muchogdps.space/muchoclient/manifest
    curl -fsS https://muchogdps.space/extensions/welcome

The manifest should list welcome, and the second endpoint should return JSON
with feature_id set to welcome. Plugins are loaded on PHP requests; no Docker
restart is required when the plugin directory is bind-mounted.

To disable: change enabled to false in the deployed plugin's manifest.json.

MuchoClient has https://muchogdps.space preconfigured but can use a different
HTTPS GDPS origin through the Geode mod settings. The client never executes
server code; it only presents validated text.

Server plugin code executes with PHP application permissions and is NOT a
sandbox. Only install trusted plugins. Privileged player features must
authenticate each request; the client-version handshake is not authentication.
