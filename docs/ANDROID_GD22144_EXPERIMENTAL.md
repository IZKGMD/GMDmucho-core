# Experimental Android GD 2.2.144 ARM64 fixture

This compatibility profile is intentionally **not** generic APK patching.

## Source identity

The only audited input for this profile is the user-supplied third-party
`[APK][Mod]_geometry-dash_v.2.2.144_[androidapplications.store].apk`.

- APK SHA-256: `3c7bf689fc25d21856e053634581ef8d452c44cf854ddefebf53aae6eabfe558`
- Embedded ARM64 `libcocos2dcpp.so`: 19,077,352 bytes
- Native ELF SHA-256: `dda3752ab3a912fd2293561e157ecc98eca799056abd9223a331c1c514175df7`
- `versionName=2.2.144`, `targetSdkVersion=35`, `extractNativeLibs=false`
- One ARM64 ABI (`lib/arm64-v8a`); compiled manifest package
  `com.robtopx.geometryjump`.
- Exactly **64** native API URL slots: **61 Boomlings** `/database`
  routes and **3 geometrydash.com multiplayer** routes.
- One malformed legacy `/databas/checkIfServerOnline.php` corrected to
  `/database/checkIfServerOnline.php`.

The official GD 2.209 (Android package version 2.2.145) profile remains
independent, with its own original SHA, native SHA and **113** routes.

## Rules

1. Require the exact complete APK hash when using this third-party 2.2.144
   profile. A matching library inside an otherwise modified ZIP is not
   sufficient: the manifest and package metadata must match the fixture.
2. Rewrite only NUL-terminated HTTPS PHP API URLs; keep ELF size and all
   unrelated links, executable bytes and license routines unchanged.
3. Rewrite all 64 endpoints, including the three on
   `www.geometrydash.com`. Reject missing, duplicated or unsupported
   layouts rather than reporting partial success.
4. For opt-in side-by-side installation, relocate the package ID in compiled
   AXML and ARSC to `com.muchogd.geometryjump` using the exact-length
   relocator; leave the fully qualified activity class unchanged.
5. Preserve 4,096-byte alignment for the uncompressed native library, sign
   with v2 or newer, verify signature and package ID after signing.
6. Never combine a 2.2.144 native library with 2.209 sibling ABIs in the
   same output APK.

## Verification and limitations

- The provided build-specific PHP patching function was exercised against
  the uploaded ELF: supports() returned true and all 64 unique endpoints
  were rewritten at identical ELF size.
- A locally built APK passed ZIP CRC checks, native ELF hash checks,
  ZIP alignment checks and independent v1/v2 signature verification.
- **No Android device launch, user login, online-level retrieval or
  multiplayer session was tested.** This is an experimental profile, not
  runtime acceptance or a statement about safety of a third-party mod.
- This profile does **not** solve unknown 2.2.144 binaries, all APKs, or
  the unrelated PHP 8 server endpoint acceptance matrix.
- Do not commit copyrighted third-party game APKs, native shared libraries
  or local signing keys to the repository.

## Tests

Run `php tests/client/android-gd22144-synthetic.php` and the existing
`tests/client/android-gd2209-*.php` suite. A complete build-specific
integration test needs a user-supplied fixture locally; CI must not
bundle that game binary.
