# Automatic Client Patching

MuchoCore's Auto Patch 2.0 rebuilds the distributed Geometry Dash clients for the GDPS during deployment and operator updates.

## What is patched

The patch engine rewrites supported Geometry Dash server references in both Windows PE executables and Android APK contents.

It handles:

- fixed-size HTTP and HTTPS database/root URL layouts;
- UTF-16 encoded URL layouts;
- Base64-encoded URL layouts;
- known Geometry Dash host names embedded inside null-terminated HTTP/HTTPS URL fields;
- UTF-16 host fields;
- Base64-encoded embedded URLs;
- the legacy `/databas/checkIfServerOnline.php` path typo.

The supported host catalog covers legacy Geometry Dash/Boomlings hosts and previously used MuchoGDPS deployment hosts. The target GDPS hostname is supplied by the deployment configuration.

## Safety and validation

Windows output keeps the executable size unchanged. Android output is rebuilt, zip-aligned, signed and verified.

Generated artifacts include SHA-256 checksums. A patch operation fails when the source client is invalid, when no supported server reference is found, or when the target hostname cannot be found in the resulting client.

Host-only replacement is conservative: the engine only changes recognized hosts when the target hostname fits the existing fixed-size field. Fixed URL layouts use the existing MuchoCore compatibility URL strategy so longer target domains can still be represented without changing binary layout.

## Automatic behavior

Initial VPS and shared-hosting deployments generate the patched Windows and Android clients automatically.

For an existing VPS installation:

`sudo mucho client-patch`

rebuilds and publishes the current clients into `storage/clients`.

After changing the configured GDPS domain through the MuchoCore operator CLI, client patching is run automatically. After a successful MuchoCore core update, client patching is also run automatically as a best-effort post-update step.

The operation does not update a player's existing installation. It produces the correct patched client for the current GDPS so operators can distribute that client pack to players.

## Generated artifacts

Each successful operation publishes:

- `GeometryDash-MuchoGDPS.exe`
- `GeometryDash-MuchoGDPS.apk`
- `MuchoGDPS-Client-Pack.zip`
- `storage/clients/manifest.json`

The manifest identifies patch engine version 2.0, GDPS URL/name, client versions, source checksums and generated artifact checksums.
