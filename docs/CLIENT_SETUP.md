# MuchoCore Client Setup

This guide explains how to connect a legally obtained Geometry Dash client to a MuchoCore GDPS.

## 1. Finish the server installation first

Install MuchoCore and make sure the public server responds:

```bash
curl -i https://YOUR-DOMAIN/health
```

Expected compatibility health response:

```text
1
```

The server's Geometry Dash-compatible endpoints are exposed below:

```text
https://YOUR-DOMAIN/database/...
```

MuchoCore also exposes a separate JSON API:

```text
https://YOUR-DOMAIN/api/v2/...
```

The JSON API is for management and integrations. The Geometry Dash client itself uses the `/database/` compatibility endpoints.

## 2. Choose a client URL

For Geometry Dash 2.2 desktop clients, the main hardcoded URL is commonly:

```text
https://www.boomlings.com/database
```

The binary expects the replacement to have the same byte length. That URL is 34 bytes long.

Example:

```text
https://gdps.example.com/database
```

Before patching, check its length:

```bash
python3 - <<'PY'
url = "https://gdps.example.com/database"
print(len(url))
PY
```

The result must be exactly:

```text
34
```

Do not continue if the length is different. Use a domain name whose resulting URL has the required length.

## 3. Patch a Windows 2.2 client

Back up the original executable first.

Run:

```bash
python3 tools/client-patch.py \
  --input "/path/to/GeometryDash.exe" \
  --output "/path/to/GeometryDash-MuchoCore.exe" \
  --server-url "https://YOUR-DOMAIN"
```

The script checks the fixed-length requirement and patches:

- the HTTPS `boomlings.com/database` URL;
- the legacy HTTP Base64 `/database` URL;
- the legacy HTTP Base64 root URL.

A successful output looks like:

```text
MuchoCore client patch complete.
...
HTTPS database URL: 1 replacement(s)
Base64 HTTP /database: 1 replacement(s)
Base64 HTTP root: 1 replacement(s)
```

If the script reports a length mismatch, **do not hex-edit around it**. Change the server hostname so the replacement has the required size.

## 4. Patch a macOS 2.2 client

Back up the application first.

Open:

```text
Geometry Dash.app
  Contents/
    MacOS/
      GeometryDash
```

Patch the `GeometryDash` executable with:

```bash
python3 tools/client-patch.py \
  --input "/path/to/GeometryDash" \
  --output "/path/to/GeometryDash-MuchoCore"
  --server-url "https://YOUR-DOMAIN"
```

Replace the original executable with the patched one only after verifying the output.

macOS may require the application to be re-signed before it can launch. For a locally modified build, use an appropriate local signing workflow for your own machine and distribution method.

## 5. Android 2.2

For Android 2.2, the GD server URL lives in the native game library. Current community documentation describes patching both `arm64-v8a/libcocos2dcpp.so` and `armeabi-v7a/libcocos2dcpp.so`, then rebuilding/signing the APK.

The MuchoCore patcher operates on the extracted `libcocos2dcpp.so` files:

```bash
python3 tools/client-patch.py \
  --input "arm64-v8a/libcocos2dcpp.so" \
  --output "arm64-v8a/libcocos2dcpp-mucho.so" \
  --server-url "https://YOUR-DOMAIN"
```

Repeat for the `armeabi-v7a` library.

Then replace the corresponding native libraries in your own APK project and rebuild/sign the APK.

The package name must be changed when you want the custom client to coexist with the original game. Keep the package name changes consistent across the APK.

## 6. iOS 2.2

iOS client creation requires repackaging and signing an IPA. The exact workflow depends on the build environment and signing method.

The same rule applies: the final client must point its Geometry Dash database URL at:

```text
https://YOUR-DOMAIN/database
```

Do not distribute an unsigned or improperly signed build.

## 7. What you do NOT need to change

You do not need to create hundreds of separate PHP URLs in the client.

MuchoCore keeps the Geometry Dash-compatible route layout under:

```text
/database/
```

The client continues to request normal endpoints such as:

```text
/database/accounts/loginGJAccount.php
/database/accounts/registerGJAccount.php
/database/downloadGJLevel22.php
/database/getGJLevels21.php
/database/uploadGJLevel21.php
/database/updateGJUserScore22.php
```

The client only needs its server base URL redirected to your MuchoCore host.

## 8. Account/content URLs

MuchoCore already has configuration for the Geometry Dash account server URL and custom content URL:

```dotenv
MUCHO_ACCOUNT_URL=https://YOUR-DOMAIN
MUCHO_CUSTOM_CONTENT_URL=https://geometrydashfiles.b-cdn.net
```

The installer sets these automatically.

Keep the official custom-content CDN until you intentionally deploy your own music/SFX library.

## 9. First client test

After patching, test in this order:

1. Start the client.
2. Create or log into a GD account.
3. Open the level browser.
4. Download a level.
5. Upload a small test level.
6. Post a test comment.
7. Submit a test score.
8. Restart the client and verify cloud save/restore.

If one of these fails, check:

```bash
docker compose logs -f
```

and:

```bash
curl -i https://YOUR-DOMAIN/health
```

## 10. Important limitation

MuchoCore currently focuses on the Geometry Dash database/account/content compatibility layer.

Do not redirect additional `geometrydash.com` multiplayer endpoints just because they exist in the original client. Those endpoints are separate functionality and are not part of the current MuchoCore compatibility surface.

## 11. Recommended distribution layout

For a finished GDPS project, keep the two parts separate:

```text
MuchoCore server
    https://YOUR-DOMAIN/

Custom Geometry Dash client
    GeometryDash-MuchoCore.exe
    / Android APK
    / iOS IPA
```

The server is updated independently from the client unless you deliberately change protocol/client configuration.

## Troubleshooting

### `Length mismatch`

Your replacement URL is not the same length as the original hardcoded URL. Choose a different domain.

### `No known endpoint strings were found`

The client build uses a different layout or URL set. Do not distribute the result. Identify the URLs in that client version first.

### Client starts but shows an empty browser

Check the database endpoint manually:

```bash
curl -i https://YOUR-DOMAIN/database/getGJLevels21.php
```

The endpoint normally expects POST parameters, so an error response from a bare GET does not by itself prove that the server is broken. Use the smoke tests for a protocol-level check.

### Accounts do not work

Verify:

- the account routes exist under `/database/accounts/`;
- the client was patched from the correct Geometry Dash version;
- HTTPS is working;
- the server database migrations completed successfully.

## References

The Geometry Dash endpoint layout and client URL conventions are documented by the GD Docs project. The Cvolton GDPS wiki also documents the fixed-length URL replacement process for Windows, macOS, Android and iOS client builds.

