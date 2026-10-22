# Shared hosting / 5v.pl — GD 2.209 patch routes and feature diagnostics

MuchoCore can expose an **experimental, incomplete PHP 7** GD 2.209 adapter
under `/mc/` on a shared host. A patched game uses paths at the **domain
root**, not `/mc/`. Apache must route only the required paths to
`mc/public/php7.php`. This page is for a controlled test installation.

## Domain-root `.htaccess`

**Append** the following directives to `/YOUR-FTP-DOMAIN/.htaccess`
immediately below the existing `RewriteEngine On` and **before** other
catch-all rewrite rules. Keep unrelated rules and backup the existing file.
Do not put these directives in `/mc/.htaccess`.

```apache
# Short GD 2.209 native client paths
RewriteRule ^a/accounts/[A-Za-z0-9_-]+\.php$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^a/[A-Za-z0-9_-]+\.php$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^checkIfServerOnline\.php$ mc/public/php7.php [QSA,L,NC]
# Some exact-length GD 2.209 URL slots need a domain-root PHP alias.
# Only GD-specific endpoint filenames; never catch unrelated PHP files.
RewriteRule ^(?:[A-Za-z][A-Za-z0-9_-]*GJ[A-Za-z0-9_-]*|getAccountURL|getCustomContentURL)\.php$ mc/public/php7.php [QSA,L,NC]

# GD 2.209 native News content files: these are NOT PHP URL paths.
RewriteRule ^gd-content/news/news_version\.txt$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^gd-content/news/news\.dat$ mc/public/php7.php [QSA,L,NC]

# Optional direct diagnostic aliases for the same news payload
RewriteRule ^news/news_version\.txt$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^news/news\.dat$ mc/public/php7.php [QSA,L,NC]
```

Why: Windows GD 2.209's fixed-length URL slots may force `/a/` or a
root-level alias; six original URLs in the 19 October build use the nested
`/a/accounts/*.php` path. The News files are **GET** requests to
`/gd-content/news/news_version.txt` and `/gd-content/news/news.dat`.
A rule matching only `*.php` cannot route them.

**Do not** rewrite unrelated static files or expose `/mc/config`,
`/mc/compat`, `php7-mode.on` or the old setup wizard. Ensure the current
private `config/php7-local.php` remains unchanged when deploying.

## Native News: configuration for a private test site

The adapter returns `-1` for News unless explicitly enabled. On a
**throwaway test host**, save `compat/php7/news.example.json` as
`/mc/config/php7-news.json`, give it your own posts, and bump the numeric
`version` after editing. In the already-private
`/mc/config/php7-local.php`, ensure the following *non-secret keys* have
their own valid values:

```php
'enable_native_news' => true,
'site_url' => 'https://your-test-gdps.5v.pl',
```

The text-only News preview lacks official CDN asset forwarding, so enabling
it may interfere with song/SFX downloads. Never enable for a public GDPS
until these requests have been tested. Full News/media management belongs to
MuchoCore **PHP 8.2+** on your VPS.

## Verify without sending credentials

Run this from the repository on a computer with Python 3:

```powershell
python tools/diagnostics/gd2209-shared-probe.py https://your-test-gdps.5v.pl
```

Or manually (Windows PowerShell):

```powershell
curl.exe -i "https://your-test-gdps.5v.pl/gd-content/news/news_version.txt"
curl.exe -i "https://your-test-gdps.5v.pl/gd-content/news/news.dat"
curl.exe -i -X POST "https://your-test-gdps.5v.pl/a/getCustomContentURL.php"
```

Expected: News version = integer `>=1000000`, News data = Base64URL-encoded
gzip, content URL = `https://your-test-gdps.5v.pl/gd-content`.
`404` usually indicates missing Apache rewrite rules; `-1` usually
indicates disabled/unavailable PHP7 features; `503` points to runtime,
configuration, or database failure. These are diagnostics, not proof of
full-game support.

## Registration: an intentional PHP 7 limitation

`registerGJAccount.php` and `loginGJAccount.php` are **disabled by
default** via `enable_accounts=false` and
`enable_registration=false`. A patched GD 2.209 client may display
“Something went wrong” because the server intentionally answers `-1`.
Do **not** publicly enable real registration or upload passwords on
unmaintained PHP 7.0: the test adapter is not production ready. Use the
modern PHP 8.2+ MuchoCore server/VPS for player accounts, GD 2.209 News and
supported editor/social features.

Working `/a/getGJLevels21.php` only establishes that **public search**
works. It does not imply any account/News/social endpoint is available.

## Public level downloads (GD 2.209)

Unlike level searches, opening a level calls `downloadGJLevel22.php` (often
through the shortened `/a/` URL on long 5v.pl domains). The experimental
local PHP7 handler now accepts a public stored levelString up to **8 MiB**
instead of 1 MiB, but still returns `-1` for nonexistent, deleted,
unlisted, friend-only and over-limit levels. A visible search result does not
prove that the separate download route is reachable.

Inspect a **public test level ID** (not a private level or credential) without
printing the level content:

```sh
curl -sS --output /tmp/mucho-level-response.txt \
  --write-out 'HTTP %{http_code}, bytes %{size_download}\n' \
  -X POST 'https://linasgdpsprivatese.5v.pl/a/downloadGJLevel22.php' \
  --data 'levelID=REPLACE_WITH_PUBLIC_LEVEL_ID&gameVersion=22&binaryVersion=51'
```

A very small `-1` response means the local adapter rejected the request.
A `404` or HTML error suggests a missing/incorrect domain-root rewrite;
`503` means runtime/database/bootstrap failure. A larger response still needs
real GD 2.209 client acceptance testing. Do not publicly enable password
registration merely to fix a level-download error.

## HTTP 503 after replacing `/mc/` from a newer PHP7 archive

The `-1` body with **HTTP 503** is a bootstrap/configuration failure, not
proof that an individual Geometry Dash endpoint is implemented incorrectly.
The archive intentionally **omits** private `config/php7-local.php` and the
`public/php7-mode.on` activation marker. Preserve these files between
updates; do not replace the real MySQL host/password with placeholders from
`compat/php7/config.example.php`.

As of this fix, the News URL discovery, version and feed (GET requests) need
only the activation marker, valid private config, news flag and JSON file,
**not a working MySQL connection**. Existing level/user/account endpoints
still require the `pdo_mysql` PHP extension and a reachable database.

After updating `mc/public/php7.php`, verify:

```sh
curl -sS -w '\nHTTP %{http_code}\n' https://linasgdpsprivatese.5v.pl/gd-content/news/news_version.txt
curl -sS -w '\nHTTP %{http_code}\n' -X POST https://linasgdpsprivatese.5v.pl/a/getGJLevels21.php -d 'gameVersion=22&binaryVersion=51&type=0&page=0'
```

- News `200` with a seven-digit integer confirms the local JSON/feed path.
- News `503`: inspect the PHP error log and check the local PHP7 marker,
  config syntax and validity of `php7-news.json`; never share secrets.
- Levels `503` while News `200`: focus on `pdo_mysql`, DB host, database
  name, DB user/password, and permissions. Don't recreate a populated DB.
- Level `-2` with HTTP `200`: empty public level list, not a broken DB.

**Do not** enable registration/writes on EOL PHP 7 merely to overcome a 503.

## Native friend requests (isolated test installs)

For an existing native database, import `mc/compat/php7/social.sql` into the
same database after backing it up. The migration adds two independent tables
and does not replace player records. Upload updated `social.php`,
`runtime.php`, `php7.php` and preserve the private config.
Opt in with `'enable_social' => true` along with
`enable_accounts` (and `enable_writes` for modifications).
This supports requests, accept/ignore/cancel, friends list and removal;
blocking, user-level privacy settings and direct messages remain unsupported.
Use only on isolated test installs while PHP 7.0 is unsupported.

## Termux offline test account recovery

The native PHP7 adapter stores two independent bcrypt hashes. If a local
password check shows both do not match, run
`php tools/mobile/php7-account-reset.php ACCOUNT_ID USERNAME` locally in
Termux, after obtaining the file from GitHub. It prints SQL; it does not
connect to MySQL or upload credentials. Back up the existing database,
then run the SQL only for an account you control in phpMyAdmin.
