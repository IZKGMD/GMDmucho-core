# Native GD 2.209 Client Patch Studio (MuchoCore)

The **PHP engine inside MuchoCore** is authoritative for the two SHA-pinned
Windows GD 2.209 builds. The admin panel's already-working chunk uploader and
patch controls are unchanged. This CLI is a second, non-web entry point to the
same engine; it does not require Python.

## Supported Windows executables

| Known original build | Size | Expected embedded API URLs |
| --- | ---: | ---: |
| 13 October 2026 | 13,158,400 bytes | 111 |
| 19 October 2026 | 13,178,880 bytes | 113 |

These are **specific original SHA-256-verified builds**. An executable with a
matching filename but a different SHA-256 is not supported by the exact-build
patcher. The patcher never changes `.text` machine code, PE layout, or file
length; it rewrites null-terminated API URLs within `.rdata` only.

## Preflight (read-only)

From the MuchoCore repository on a computer with PHP 8.2+:

```sh
php bin/mucho-patch-gd2209.php --input="/path/to/GeometryDash.exe" \
  --server="https://your-gdps.example" --dry-run
```

Preflight authenticates the source by SHA-256, checks the PE section table,
calculates all target URLs, confirms their byte lengths fit, verifies the exact
111/113 endpoint count, and computes the expected output SHA-256. It does
**not** write an EXE, contact the destination URL, or certify real gameplay.

To inspect every rewritten endpoint and its route mode:

```sh
php bin/mucho-patch-gd2209.php --input="/path/to/GeometryDash.exe" \
  --server="https://your-gdps.example" --dry-run --json
```

## Patch a client

```sh
php bin/mucho-patch-gd2209.php --input="/path/to/GeometryDash.exe" \
  --server="https://your-gdps.example" \
  --output="/path/to/GeometryDash-MuchoCore.exe" \
  --manifest="/path/to/patch-report.json"
```

The original executable and any existing output file are preserved. The
engine writes to a temporary file next to the destination, verifies its
SHA-256 and length, then renames it into place. When something fails, it
removes the temporary file. Manifest filenames also cannot be overwritten.

The `patch-report.json` includes the original and resulting SHA-256 hashes,
source build, URL replacement count, and per-route path/mode. All reports mark
their verification level as `static-binary-only`.

## Shared hosting and long hostnames

The game contains fixed-size strings. For a long host (such as
`linasgdpsprivatese.5v.pl`), a URL like
`/database/getGJLevels21.php` may not fit. The patcher automatically tries:

1. Canonical `/database/name.php`
2. Short alias `/a/name.php`
3. Root alias `/name.php`

If even the root alias does not fit, patching fails instead of truncating
bytes. The new `route_modes` counts and `routes` map show exactly which
aliases the destination server must serve.

For a MuchoCore PHP 7 experimental installation under `/mc/` on Apache,
add *appropriate scoped rewrite rules* at the **domain document root**, not
just inside `/mc/`, and verify that POSTs to `/a/getGJLevels21.php` are
forwarded to the actual PHP 7 front controller. The `--server` argument is
the **HTTPS origin only**, without `/mc`, `/a`, or `/database`.

Do not automatically rewrite all requests or expose the web-facing PHP 7
installer, configuration, or credentials. PHP 7.0 is unsupported and the
experimental GDPS adapter is not certified for public player accounts.

## Validation and limitations

The CI regression suite exercises:
- Known synthetic PE64 fixtures and malformed header/section rejection;
- `.rdata`-only rewrites and unchanged payload outside the section;
- Exact route manifests and `/a/` fallback for long domain names;
- Fail-closed behaviour for domains that cannot fit;
- The established Windows/Android/admin patcher tests.

**Not certified by static tests:** an actual GD client connecting on the
network, platform-specific game UI flow, HTTP POST transparency on a hosting
provider, or complete GD 2.209 API parity. Verify those using real-client
traces. Client patching does not make an incomplete server feature-complete.
