# MuchoLauncher Android 1.1.1

Native Android launcher in MuchoCore's dark violet palette. Based on Geode
Android Launcher 1.8.0, pinned to `38bc97b7b6551acb1295dd241231ca22e2cd8995`,
licensed under Boost Software License 1.0; upstream notices are retained.

## Install

Install the signed APK and open MuchoLauncher. Install Geometry Dash
2.2081 (Android versionName 2.2.144 or 2.2081) first. ARM32 and ARM64,
Android 6.0 or newer. Sideloaded and renamed packages can be selected using
«Выбрать игру» on Home or Settings; the selected package survives process restarts.
The installer and store signing certificate do not determine compatibility.
Changed versionCodes are normalized to 41 for the bundled loader only after the
versionName compatibility check. A removed selection requires choosing again.
The game is not distributed in this APK. Root and a separate
Geode app are not needed.

The APK bundles Geode 5.10.1 and the already verified MuchoClient 1.2.1 package.
An initial check restores bundled client/resources to app-owned storage. Play
rechecks the package and restores damaged payload files, checks the bundled
loader, game version and native library architecture. Saves and
third-party mods are not repaired or removed. Loader replacement requires
reinstalling the APK.

The game activity is not exported. External links cannot select safe mode or
skip preparation. Client liveness is checked through `MuchoClientHealth_v1`
in its actual loaded library. Inputs remain blocked until that signal is ready;
failure after 60 seconds returns to the launcher. Loading frames continue so
Geode can complete asynchronous initialization. This policy applies to game
launches through MuchoLauncher, not to the separately installed original app.

Saves are separate from Geometry Dash and other launchers, under the app's
private `files/mucho/save` directory. Export ZIP before uninstalling. Creator Hub
requires the server plugin to be deployed/enabled. It is opened in MuchoClient,
not implemented as a separate web dashboard in this launcher.

## Build

Workflow `build-mucholauncher-android.yml` downloads the verified client from
run 38543436028, checks its fixed SHA-256, obtains loader archives from Geode
version metadata and checks those hashes. It builds an unsigned release APK
for both ABIs, runs corruption/traversal/atomic-replacement tests, then checks
the actual APK payload. Signing is performed outside CI with the retained
private MuchoLauncher key; private signing material must never enter git.

Tests cover both ARM payloads, split-APK library inspection, version metadata,
and an Android 15 emulator picker with a renamed, sideloaded synthetic app.
The fixture contains no GD code and cannot validate gameplay. Modified game
binaries still need physical-device testing. For ARM32-only GD on a 64-bit phone,
install the signed Android32 launcher over the current launcher; the same
application ID and signing key preserve app data. The launcher reports this
architecture mismatch before trying to load incompatible libraries.

The bundled MuchoClient 1.2.1 adds adaptive JPEG/PNG attachments, zoom/pan and server-local NSFW screening. Enable these in the matching core; setup is in docs/features/message-images.md. Images are not sent to an external moderation API.
