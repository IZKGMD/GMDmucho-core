# Geometry Dash 2.209 Android: native patch and side-by-side installation

## Confirmed source build (21 Oct 2026)

This implementation is constrained to the *user-supplied* ARM64 APK; a different
2.209 APK is **not automatically compatible** and must be verified separately.

- Uploaded APK SHA-256:
  `f85d71beaa23c13eb6df0f4a9a636f522a7caf309306ff831ef43f5580341e7b`
- APK size: 174,568,364 bytes; Android `versionName=2.2.145`
- Original installable application ID: `com.robtopx.geometryjump`
- Native library: `lib/arm64-v8a/libcocos2dcpp.so`
- Native library SHA-256:
  `46b0e17f6323979f5cd415a8a241269ac384f796a5ef35e668dfe7af3346d0c1`
- Exactly 113 NUL-terminated Boomlings PHP API URLs confirmed in that library
  (including Threads, notifications, editor sounds and moderation/reporting APIs)

The versionName above is the package metadata from this uploaded artifact,
not a claim about every official Android distribution of Geometry Dash 2.209.

## Admin workflow

1. Open `/admin/?page=clientpatcher` with a privileged admin account.
2. Enter the HTTPS public GDPS root (e.g. `https://muchogdps.space`).
3. Keep **Install alongside the original Geometry Dash** checked.
4. Upload the original APK and download the newly generated
   `GeometryDash-MuchoCore.apk`.
5. Install the output as a *separate* app. The official app is not uninstalled.

For this exact native build, the patcher:

- Rewrites 113 full API endpoint URLs; any old malformed `/databas/` endpoint
  becomes `/database/`. URL bytes cannot exceed each original NUL-terminated
  fixed-size field. Unsupported domains fail without returning a patched APK.
- Changes **only the binary AXML application ID** from
  `com.robtopx.geometryjump` to `com.muchogd.geometryjump` while leaving
  fully qualified Java activity class names untouched.
- Also updates the matching `resources.arsc` resource package name.
- Reuses all unchanged ZIP entries instead of scanning/recompressing all
  6,184 original assets; replaces only the native ELF and, when side-by-side
  is requested, the manifest/resource table.
- Removes stale V1 signature metadata and then zipaligns, signs with
  the server's persistent MuchoCore Android key and verifies the new signature.
- Verifies the signed APK's application ID and native-library hash before
  returning a download link.

**Signing alone cannot install an app alongside the original** when both APKs
share the same Android package ID. The new ID is why co-installation becomes
possible. The two packages have distinct app data and local saves. Automatic
transfer of the original application's saves is not included.

## Performance

Upload chunks are negotiated up to 8 MiB (within the default Caddy 75 MB body,
PHP 64M upload and 70M POST limits). Slow links can reduce chunk size and
recover the last confirmed offset after a lost HTTP response.

A reduction in request overhead **does not guarantee** an increase in upload
bitrate over a slow 4G/LTE uplink; use the measured MiB/s on the admin screen
to distinguish network time from APK rebuild/signing time.

## Limits and acceptance

- Only the listed native library checksum is accepted by the GD 2.209-specific
  fast patcher. Unknown 2.209-like binaries fail closed.
- An additional app may behave differently if its Java/native code depends on
  the original package name for licensing, stores or external integrations.
- No real-device launch/login/online-level acceptance has yet been recorded.
  Perform installation and GDPS communication tests before declaring support
  release-certified.
- No commercial/copyrighted game binaries are checked into the MuchoCore repo.
