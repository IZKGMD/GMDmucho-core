# First production GDPS adoption — no data loss or unverified clients

This checklist applies before asking an existing GDPS (especially one with
active players) to switch its clients or DNS to MuchoCore.

## Non-negotiable prerequisites

1. Obtain the GDPS owner's permission and source database schema/SQL dump.
   Do not request public disclosure of DB passwords, admin sessions, or keys.
2. Identify exact source core/fork, GD version, Windows executable and Android
   APK build. MegaSa1nt-compatible schema **is not** proof that every feature
   can be migrated.
3. Keep an independently restorable original SQL dump, media files (music,
   sounds, profile images), source Cloud Save key (if applicable), and published
   clients. Verify backup integrity; store a copy off-server.
4. Test on a separate target database and test hostname. Preserve the original
   production server and avoid an in-place schema rewrite.
5. For a Cvolton import, use read-only dry-run first and compare per-table counts.
   The new `unmapped_level_owners` count must be **zero** before apply.
   This importer now aborts when it would otherwise silently omit orphan levels.
6. Do not claim that legacy comments, private messages, friend lists, gauntlets,
   map packs, ratings or source moderation roles have been transferred. They are
   currently detected/reported separately, not automatically migrated.
7. Confirm legacy password-hash compatibility and test representative player
   logins; unsupported hashes require a recovery plan.
8. Run normal regression checks against the isolated installation. Check the
   login, account, level search, level download, upload, comments, Cloud Save,
   account recovery, scores and permissions on the intended client generation.

## Network boundary — server vs patched game

Patch output checks that strings were replaced and that Windows PE / Android
APK artifacts retain valid structure or signature. **It is not live-client
evidence.** The signer certificate differs from the original Android publisher;
an APK may require a separate installation and may not install over a differently
signed existing package.

From the source checkout or the PHP app container run:

```sh
php bin/mucho-client-network-check.php https://example-gdps-host
```

This non-destructive check makes an HTTPS request to `/health`, an actual POST
to `/database/checkIfServerOnline.php`, and a POST to
`/database/getGJLevels21.php`. It does not send player credentials.

- `FAIL` means the HTTP/TLS/proxy/wire boundary failed (403, HTML challenge,
  redirect, empty payload, incorrect liveness marker, etc.).
- `INCONCLUSIVE` means the host returned a plausible game error such as `-1`;
  investigate server logs and database contents before interpreting it.
- `OK` means the probed server endpoint responds as expected, **not** that
  an installed APK/EXE sends requests to this host or parses its responses.

Capture an authorized real game trace, including the actual request host,
scheme, path and a sanitized classification of the response. Never log
`gjp`, `gjp2`, user passwords, UDIDs, or private response payloads.
Compare installed client's architecture/version with the patched source and
inspect Android signature/package ID. Preserve the original APK/EXE bytes.

## Migration cutover and rollback

- Freeze source writes briefly only at final cutover. Export a final delta/dump
  with agreed downtime and repeat an isolated import.
- Compare account, profile, level and score counts plus random source/target
  records. Verify no unannounced ID remapping breaks external links.
- Sign in with real test accounts and open previously published levels.
- Update patch/connection settings, then test Windows and Android with native
  game clients before changing public DNS.
- Keep the original endpoint available until acceptance tests succeed.
- If tests fail, switch DNS/client distribution back to the original host;
  never restore over a live database without a separately verified backup.

Current support is partial, and GD 2.209 still needs native client acceptance.
Do not claim 100% compatibility or "10x faster" without repeatable measurements.
