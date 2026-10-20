# Experimental 5v.pl edge for MuchoCore (not standalone hosting)

As of 2026-10-20, 5v.pl publicly advertises PHP **7.0.19**,
128 MB per PHP request, 30 seconds maximum execution time, no Docker,
and no `exec()`. MuchoCore requires PHP **8.3+** (`composer.json`).
**The complete MuchoCore server, admin panel, migrations and worker
cannot run natively on this hosting environment.** Do not downgrade the
core to PHP 7 and do not claim otherwise.

The optional `tools/hosting/5v-edge.php` is a *PHP 7-compatible, narrow HTTP
relay* for GD protocol endpoints. **The real core and database still run on
your own VPS or other PHP 8.3+ host.** It can give a patched game client a
`https://<your-site>.5v.pl/` URL only if the host allows outgoing HTTPS
cURL and does not rewrite the response. This arrangement is experimental
and has NOT been verified on a live 5v account.

## Before deploying

1. Register your own 5v subdomain and check that the account's terms permit
   relaying the game requests to a backend you operate.
2. Upload `tools/hosting/5v-probe.php` as `5v-probe.php` in your web root,
   open it in a browser, inspect PHP version and `curl`, and **delete it**.
3. Keep your HTTPS MuchoCore backend online and confirm its GD endpoints
   are accessible through `https://YOUR-OWN-VPS-HOST/database/`.
4. Edit the fixed `$origin` in `5v-edge.php` to that HTTPS root;
   never allow clients to supply the upstream address.

## 5v web root files

Upload `tools/hosting/5v-edge.php` to the 5v site's web root.
Upload `tools/hosting/5v-htaccess.txt` as **`.htaccess`** in the same
directory. Do not upload MuchoCore's root `.env`, secrets, vendor, source
tree, database or client binaries to 5v.

Your URL layout:

```text
GD 2.209 patched client
    -> https://YOUR-SITE.5v.pl/database/getGJLevels21.php
    -> 5v-edge.php (PHP 7 + cURL)
    -> https://YOUR-OWN-VPS-HOST/database/getGJLevels21.php
    -> MuchoCore 1.1.0 server on PHP 8.3+ + database
```

Only GET/POST and a narrowly validated GD API route shape are relayed.
No admin area or general-purpose proxy is exposed. Responses are sent
as raw text without an HTML wrapper; outgoing HTTPS certificate checks
stay enabled. Payloads are limited to 8 MB and responses to 12 MB for
the free-host memory budget. This means the edge is **not suitable for
unrestricted cloud saves, all user-generated levels or heavy traffic**
without a redesigned streaming relay.

## Verifying the actual 5v transport

Test that `https://YOUR-SITE.5v.pl/health` returns only `1` and that
the **real** client-facing route returns the same byte-for-byte body as
the VPS origin for an equivalent request:

```bash
curl -sS --data 'gameVersion=22&binaryVersion=51&type=0&page=0' \
  'https://YOUR-SITE.5v.pl/database/getGJLevels21.php' -o edge-response.txt
curl -sS --data 'gameVersion=22&binaryVersion=51&type=0&page=0' \
  'https://YOUR-OWN-VPS-HOST/database/getGJLevels21.php' -o vps-response.txt
sha256sum edge-response.txt vps-response.txt
```

Compare identical responses from a non-personal public query. Do not
expose user secrets in those commands. Content may vary naturally
between repeated live requests; identical hashes are a useful
transport check, not a complete protocol acceptance test.

The 5v host may add advertisements or intercept responses on free plans;
if it does, GD clients can reject the wire body and there may be no
application-side solution. The host also terminates HTTPS, meaning
account credentials and requests pass through it. Shared source IP can
trigger backend MuchoProtect limits; the relay does **not** forge the
source IP or bypass abuse defenses. Expect added latency and a single
point of failure; keep this test-only until separately certified.

## Windows client

The **original** GD 2.209 build with SHA-256
`524265b3781596fcc7c9e4d60dc5ac2d0a4cd9530ee3ea637e123f9ac1f9b6a8`
is supported by both the web PHP patcher and the Python patcher with
the new `.rdata` full-URL method. Patch the client using the HTTPS 5v
subdomain as the server **root** (no `/database` suffix). Do not assume
that successful binary patching certifies actual client connectivity.

For native PHP 8.3+ shared hosting rather than 5v's PHP 7, see
`docs/CLIENT_SETUP.md` and the existing shared-hosting install path.
