# MuchoCore on shared hosting — native PHP 7.0 runtime

This is an ongoing native port, running entirely on the shared host with its
own PDO/MySQL connection. It does not proxy to a VPS, load PHP8 application
classes, or require Composer. It is **not yet full MuchoCore feature parity**.
PHP 7.0 is obsolete; provider and real GD client acceptance are still separate
from the automated checks below. The full PHP8 core remains available for
shared hosts running PHP 8.2 or newer.

## Implemented

| Area | Current native behavior |
| --- | --- |
| Installation | Standalone `public/php7-setup.php` for a new empty native database; existing installations use the additive upgrade below |
| Accounts | Registration, password/GJP2 login, banned/inactive checks, bcrypt, registration/login attempt limits; legacy XOR GJP accepted for authenticated actions |
| Levels | Public search/list, author search (type 5), recent (type 2), downloads up to 8 MiB, authenticated upload/update up to 8 MiB, owner/friend private downloads and editor extras |
| Users | Profiles/search/top/creator rankings, all character forms/colors, privacy/settings, authenticated browsing and score updates returning legacy userID |
| Comments | Local level comments, own profile posts, history, pagination, recent/likes ordering, UTF-8 text, GD 2.209 exact timestamps |
| Votes | Authenticated likes/dislikes for levels and both comment kinds; duplicate requests are idempotent |
| Saves | Local database backup/sync up to 8 MiB, byte preservation and SHA-256 integrity; account ID or username authentication |
| Social | Friend requests/acceptance, symmetric friends, blocks, inbox/outbox/read/per-side deletion, message/history privacy |
| Admin | Original VPS shell, styles, GD sprites and page templates; native roles/bans, ratings, comments, songs, branding/settings, audit and SQL backup |
| Administrator factors | Original authenticator/recovery cards, local QR, TOTP with replay protection, hashed one-time recovery codes, password/factor reauthentication and session revocation |
| News | Original News Board with drafts, pinning, up to six images and a YouTube preview; native GD 2.209 media serialization and private draft images |
| Routing | `/a/`, `/database/`, `/accounts/`, nested account routes and `/mc/` installation paths |

Comments, votes and saves use `php7_comments`, `php7_votes` and
`php7_cloud_saves`. These are new native tables. This milestone does **not**
import PHP8/Cvolton comments or saves; existing data must be migrated explicitly.
Public comment reads and history exclude deleted, unlisted and friend-only
levels. Writes require authenticated active accounts. Comment deletion is
owner-bound and kind-bound. Comments are limited to 300 UTF-8 characters and
one post per account per ten seconds, serialized by an account row lock.
Votes lock the target before applying the change in vote direction.
Client score uploads cannot change roles, creator points or level ratings.
These score fields are client-reported, not an anti-cheat certification.

## Upgrade an existing native PHP7 installation

1. Preserve the current files and database backup.
2. Upload the new `mc/compat/php7/` files and `mc/public/php7.php` from the
   native FTP archive. Preserve private `mc/config/php7-local.php`, the
   existing `mc/public/php7-mode.on` marker and all existing data.
3. Open `/mc/admin.php` and use **Update database** after owner login.
   Alternatively, select the same existing native database in phpMyAdmin and
   import `mc/compat/php7/features.sql`. It uses only `CREATE TABLE IF NOT EXISTS`
   for native feature tables. It neither alters nor clears existing tables.
   It can be imported again. No installer reset or database recreation.
4. Keep the root routing rules described below. `/mc/...` paths now have
   their installation prefix removed before endpoint matching.
5. Account processing still needs `enable_accounts => true` in the private
   configuration. Registration separately needs `enable_registration => true`.
   Mutations need `enable_writes => true`. Backup/sync additionally needs
   `enable_cloudsave => true`; social needs `enable_social => true`.
   These can now be changed in the admin settings. The archive leaves switches off.
6. For saves configure PHP `post_max_size` to at least `26M` and
   `memory_limit` to at least `128M`. A URL-encoded 8 MiB save can approach
   24 MiB on the wire. The accepted decoded save limit remains 8 MiB.
   A host's lower POST/proxy limit can still reject a request before PHP runs.

Example feature switches in the **existing private** config (retain DB fields
and the installer-generated auth pepper):

```php
'enable_accounts' => true,
'enable_registration' => true,
'enable_writes' => true,
'enable_cloudsave' => true,
```

## Browser setup without manual tokens

1. Copy `compat/php7/config.example.php` to private `config/php7-local.php` and
   fill the credentials for your **empty MySQL database**.
2. Open `/mc/admin.php` (or `public/php7-admin.php`). Prove knowledge of the
   existing private DB password and choose the owner account/email/password
   and HTTPS origin. A selected checkbox enables game account processing,
   registration, writes, saves and social functions. Secrets and the routing
   marker are generated locally after successful operator authentication.
3. The browser creates the native schema only if the database is completely
   empty. Existing native accounts can instead be promoted to first owner
   only with BOTH their correct password and the private DB password.
   First ordinary registration never grants admin rights.
4. Existing owners use their game account to log in. The panel offers an
   additive **Update database** button; it does not reset the installation.
   SQL import remains available as an alternative.

The older `php7-setup.php` key-based wizard remains available for compatibility;
new setup does not require that flow. PHP8 `/install.php` remains separate.
Never upload or initialize over a PHP8/Cvolton database as an implicit migration.
The native archive contains no live settings, credentials or activation marker.

## Native administration

The owner manages roles/bans, settings, songs and Native News. Elder moderators
can apply ratings and soft-delete/restore levels; regular moderators can
remove comments. Public pages never expose account passwords or database secrets.
The panel rechecks roles and account state on every request, uses credential-backed
sessions and CSRF on all mutations, rotates sessions after login and expires
idle/old sessions. Owner changes cannot remove one's own owner access.

`tools/release/sync-php7-admin-ui.py` derives the PHP7 templates and News classes
from the original source; `admin-ui/upstream-sources.json` records their hashes.
It preserves the original CSS and GD assets. The shared host bridge changes
storage, mount URLs and PHP syntax. News Board and authenticator/recovery are
original templates too. Separate administrator identities, one-time password invitations, custom roles, assignment and activation are supported. Native passkeys use the pinned original verifier and the same administrator principal registry; password reauthentication and enabled TOTP remain required for enrollment. Database Backup Center uses the original shared-host page and PHP service with private compressed archives and verified downloads. CSP authorizes original inline blocks
with per-response nonces; no `unsafe-inline` is enabled. QR images are generated
locally, without sending authenticator secrets to an external QR service.

Each administrator can configure their own factor in **Administrators**.
The setup expires after ten minutes. Enabling or disabling a factor revokes
other sessions. A TOTP time step and each recovery code are accepted once.
Store the recovery codes when displayed; only hashes persist in the database.

SQL backup uses a repeatable-read consistent snapshot and streams one row at
one time without buffering entire tables. Restore is exercised on a second
empty disposable database, including player accounts and editor extras.
The SQL dump includes password hashes/player data and should be kept private;
it excludes the private host/database configuration.

Native social settings implement messages open/friends/closed, friend requests
open/closed and comment history open/friends/closed. Blocks remove friendship
in both directions and pending requests, prevent new direct messages and revoke
friend-only level/history access. Each private message deletion affects only
the authenticated party's own inbox/outbox copy, including direct download.
Earlier `main` friend tables (`sender_id`/`receiver_id`, `php7_friendships`)
continue using their original scoped compatibility adapter. The upgrade does not
rename or silently import those tables; advanced native social features require
the current schema. Both variants retain ignore/cancel semantics.

## Domain-root Apache routing

For an installation in `/mc`, add these rules below an existing
`RewriteEngine On`, before catch-all rules in the **domain-root** `.htaccess`.
The native ZIP includes them as `5V_ROOT_REWRITE_RULES.txt`.

```apache
RewriteRule ^(?:a|database|accounts|api)/(?:accounts/)?[A-Za-z0-9_-]+\.php$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^(?:getAccountURL|getCustomContentURL|checkIfServerOnline)\.php$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^gd-content/news/news_version\.txt$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^gd-content/news/news\.dat$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^news/news_version\.txt$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^news/news\.dat$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^(?:gd-content/)?news/mcnews_[a-f0-9]{64}\.jpg$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^news/image\.php$ mc/public/php7.php [QSA,L,NC]
```

Keep other root rules. An HTTP 404 at `/a/...` indicates routing must be
checked. An adapter HTTP 503 indicates config, extension or schema failure;
it does not prove a protocol error. Check PHP logs without publishing secrets.
The adapter's browser `/mc/health` checks native DB connectivity.

## News configuration

Open **News Board** as the owner. The first visit applies the original additive
News migrations and imports legacy `config/php7-news.json`, if present, once.
The original JSON is retained as a backup. An existing SQL board is authoritative.
The marker `config/php7-news-sql.on` selects SQL reads; preserve it with the DB.
Create/edit posts in the board: version numbers increase automatically and
stale forms are rejected. Drafts and their image bytes stay private.

Set `enable_native_news => true` and `site_url` to the HTTPS **origin** of
your own host, e.g. `https://your-gdps.5v.pl`, without `/mc`.

- POST `/a/getCustomContentURL.php` returns the origin plus `/gd-content`.
- `/gd-content/news/news_version.txt` returns the integer version.
- `/gd-content/news/news.dat` returns the native GD payload.
- `/news/*` aliases are also available for diagnostics.

Before migration, legacy text/link JSON News still works without MySQL.
The full board and its media require MySQL. JPG/PNG uploads and YouTube previews
require PHP GD with JPEG/PNG support; unavailable GD produces a validation error.
Images are decoded/re-encoded to bounded JPEGs, never stored as uploaded names.
For six 5 MiB images plus a preview, configure `upload_max_filesize >= 5M`,
`post_max_size >= 37M`, `max_file_uploads >= 7` and sufficient PHP memory.
The host can enforce lower request limits before the application receives files.
Other official Music/SFX assets use the fixed-origin streaming proxy once the root rewrite rule is installed.

## Full-core upgrade

The package now includes all 197 application classes, 73 tracked migrations,
and compiled original admin modules. `tools/release/compile-php7-domain.php`
produces the PHP 7.0 mirror using pinned PHP-Parser; original source hashes are
recorded in `compat/php7/core/sources.json`. No compiler or Composer runs on the
shared host.

On an existing native installation, open **Server → Complete migration** as
owner. The updater pauses game requests, drains in-flight requests, creates a
private verified database backup, applies bounded schema batches and imports
native presentation, privacy, level metadata, comments, votes, both friendship
schemas, messages and cloud saves. Cursors commit with data; retry resumes after
interruption. The complete API marker is written only after import finishes.
Historical native tables remain available for recovery; after activation they
are no longer the live API storage. Do not manually turn off the complete marker
and resume old writes after new game data has been created.

Cloud saves retain their raw bytes and SHA-256, encrypted in the original
AES-GCM format with a private persistent key. PHP 7.0 uses the pinned phpseclib
compatibility implementation; NIST, tamper, and cross-runtime tests cover it.
Never delete or regenerate `config/cloudsave.key` for an installation containing
encrypted saves. Back up that private key separately along with database backups.
The shared request adapter retains an 8 MiB decoded save limit; provider POST,
execution-time and memory limits also apply.

For FTP updates, `tools/release/build-php7-core-pack.php` builds a code-only pack
and SHA-256 metadata. Upload both to private `config/`, then use the owner-only
installation action. The installer checks every path and file hash and switches
an immutable build pointer only after all files are written. Test fixtures and
private installation configuration are excluded. Existing panel identities,
roles and TOTP/recovery state stay in the native principal registry.

The port includes clans, threads/following, level lists, quests/rewards,
sanctions and the other implemented original routes. Its parity is with the
repository's implemented behavior; any original experimental client behavior
still needs real GD client acceptance. Recovery renders the original form and
uses provider PHP mail; delivery requires working hosting mail configuration.
The fixed official CDN proxy supports GET/HEAD and single byte ranges, with a
64 MiB response limit. Apply the supplied root rewrite rules for root-relative
client URLs. GD image features require the PHP GD extension.

The admin exposes original clans, collections, profile customization, social
moderation, analytics, database browser, local API tester, client feature flags,
plugin diagnostics, intelligence and security-monitoring pages. Plugin PHP code
must itself support the installed PHP version. VPS process control is replaced
by provider operations; shared code updates use verified immutable code-only
packs from the Server page. Provider cron can run the shared scheduler below.
Password, TOTP, recovery-code and passkey authentication use the native principal
registry. Passkeys require PHP OpenSSL and mbstring, HTTPS, and a compatible
browser/device; private credential keys never leave the user's authenticator.
Track remaining platform/client acceptance work in issue #148.

### Local queue execution on shared hosting

After installing the current code-only core pack, **Server → Queue tasks**
lets the owner process up to four local jobs per authenticated, CSRF-protected
request (up to four seconds between jobs). It runs original `level.index`
logic and bounded maintenance without a daemon or shell. A nonblocking private
file lock prevents overlapping runs; the original queue's worker/attempt lease
fencing, delayed retries and abandoned-lease recovery are retained. Cleanup
deletes at most 500 expired cache/job rows and 128 download dedupe rows per job.

The panel button runs an operator-triggered batch. Provider cron enables
unattended execution. By default the worker reserves only local jobs; external
delivery is opt-in through the owner's External notifications settings. Device
acceptance and a host's actual cron configuration are separate from code tests.
The original queue's optional job-type filter defaults to all types, preserving
the existing VPS worker behavior. A pinned older snapshot fails closed with an
instruction to install the uploaded build before using the shared runner.

### Provider cron and daily backups

The private `compat/php7/cron.php` entry point is CLI-only. Configure the hosting
provider's cron scheduler to run `php /absolute/path/to/mc/compat/php7/cron.php
--backup` every minute, using the provider's CLI PHP binary. The command is
shown to the owner on the Server page. It uses the existing private database
configuration and a shared runtime lock. No public trigger, token, VPS or open
browser is needed. Web requests to the entry point fail closed.

Each invocation runs a bounded local queue batch. An hourly checkpoint enqueues
maintenance; `--backup` additionally creates one verified compressed database
backup per 24 hours. Separate scheduler and backup locks prevent overlap. Backup
time is recorded only after gzip and SHA-256 verification succeeds, allowing a
failed backup to retry. Existing backup files are preserved. Monitor disk quota;
private encryption and signing keys still need a separate private backup.

The archive provides the scheduler, but does not configure a provider's control
panel automatically. A host with no CLI/cron capability can still use the owner
queue and backup buttons. Shared UI, installer messages, errors and patcher
progress use English, including the Plain interface and pages without JavaScript.

### Passkeys, notifications and remaining operator pages

Passkeys use `report-uri/passkeys-php` v2.0.1 at commit
`f8cddbfe00f9ecff1eebeb6bcae18b111f6bc468`, with original MIT sources, file hashes
and a PHP7-generated mirror. WebAuthn retains RP/origin, user presence and
verification, single-use five-minute challenges, user-handle binding and
signature-counter checks. Credentials are stored in `php7_admin_passkeys`,
bound to panel IDs rather than game account IDs. A linked banned/deactivated
game account cannot use a passkey to regain panel access. Enabled TOTP remains
required after a valid passkey assertion. Enrollment/revocation also requires
the administrator's current password and enabled factor.

External notifications retain the original event JSON and optional HMAC-SHA256
signature. The owner explicitly chooses a public HTTPS endpoint and events;
secrets stay in private hosting configuration and are not returned in forms or
audit details. cURL pins a validated public IPv4 resolution, verifies TLS,
does not follow redirects, bounds response bytes and times out after five
seconds. Disabled delivery leaves queued external jobs untouched. Queue leases,
retry delay and attempt limits also apply to deliveries.

Control v4 uses the original bulk/search/history/settings modules. Shared bulk
controls are owner-only and reject changes that would bypass the native panel
protections for linked administrator accounts. Manage those accounts individually.
Migration Center uses the original source detector, adapter registry and Cvolton
importer. Preview performs source reads only. Confirmed import takes the runtime
lock, creates a verified target backup and commits supported records in one
transaction; the source is never written. Import support is the same as the
original adapter: accounts, profiles, levels and scores; detected-only datasets
are reported rather than claimed as imported. Provider time/memory and remote
MySQL connectivity limits still apply.

Search index rebuild runs in resumable batches from Server, with a private
cursor advanced after each successful upsert. Cron continues pending rebuilds.
Core Updates, Backups and Client Downloads link to the corresponding shared
Server, Backup Center and Client Patcher functionality instead of VPS commands.

## Fully shared client patcher

**Clients & extensions → Web Client Patcher** accepts an original APK (up to
512 MiB) or Windows EXE (up to 64 MiB) and a server origin. Uploads use 2 MiB
SHA-256-checked parts with an authoritative resume offset. Re-selecting the
same file resumes a lost upload; a first/last-block fingerprint prevents
accidentally combining different clients. No external processing service is used.
When updating an already activated installation, upload the ZIP's code-only
`config/php7-core-update.pack` and `.json` too, then choose **Server → Install
uploaded build**. Updating file mirrors alone does not advance a pinned core
snapshot; the patcher checks that the required core method is actually loaded.

APK work uses a bounded ZIP32 reader/writer implemented in PHP, not ZipArchive.
It preserves untouched compressed entry data, drops stale signatures, validates
entry CRCs and sizes, rejects overlapping/duplicate/encrypted/ZIP64 archives,
and aligns stored native libraries to 16 KiB (other stored files to 4 bytes).
PHP OpenSSL produces both JAR/v1 and APK-v2 signatures. Each HTTP step copies or
hashes a bounded amount of data; no Java, shell, exec, queue daemon or VPS is
required. APK processing needs 64-bit PHP, zlib, OpenSSL and sufficient writable
disk/memory; individual decoded entries are limited to 96 MiB.

Known GD 2.209 native builds retain exact SHA-256 checks and URL counts.
Unknown modern or already patched builds are refused. The verified ARM64 APK
can use the existing side-by-side package relocation; legacy APKs must disable
that option. This changes API addresses, not licensing or executable code.

The installation creates a private persistent RSA signing identity at
`config/php7-apk-signer.json`. Back it up separately from SQL backups. Losing or
replacing it changes the Android update identity; APKs previously signed on a
VPS with another key cannot update in place with the newly generated key.
Inputs/results are private, permission-checked and bound to the administrator's
session; completed results last four hours. Reset deletes that temporary job.
Expired jobs are cleaned on subsequent starts; there is no cron dependency.

CI patches a compiler-generated APK with all process-launch functions disabled,
then independently verifies v1/v2 signatures using Android `apksigner`, checks
alignment using `zipalign`, and confirms a changed byte fails verification.
Those verification tools run only in CI, never on the shared server. This does
not substitute for installation/launch on the user's Android device.

## Verification

The native source and pure routing/encoding tests run on **PHP 7.0.33**.
Disposable MariaDB tests cover registration/login, native level protocol,
public users, comments/history, duplicate and reversed votes, owner checks,
private-level visibility, client scores and cloud-save integrity/isolation.
PHP8 tests compare existing encoders byte-for-byte. Actual GD 2.209 client acceptance must also be verified on the target host.
The full-core suite additionally runs the original 12 feature integrations on
PHP 7.0 and 8.3, plus populated native-schema upgrade and encrypted-save import
fixtures for both historical social schemas.

```sh
php tests/compat/php7-native-features-pure.php
php tests/compat/php7-native-news-security.php
TEST_DB_ROOT_PASSWORD=... php tests/compat/php7-native-mysql.php
TEST_DB_ROOT_PASSWORD=... php tests/compat/php7-native-features-mysql.php
TEST_DB_ROOT_PASSWORD=... php tests/compat/php7-native-news-security.php --mysql
```

Both MySQL tests create and remove their own fixed disposable `*_ci`
databases; never grant that test runner credentials to a production database.

## Shared polish verification

`tests/compat/php7-native-social-admin-mysql.php` checks first-owner setup,
upgrade preservation, social wire/ownership/privacy, roles, ratings/restore,
news revision conflicts, editor metadata, deduplicated downloads and SQL
backup/restore. It runs on PHP 7.0 and PHP 8.3 in CI.

## Termux: recover a TEST account password without database access

On Android Termux run `pkg install php curl` and retrieve
`tools/mobile/php7-account-reset.php` from this repository, then run
`php php7-account-reset.php 2 izk` (substitute your test account ID/name).
The program prompts for the new password twice with input hidden and prints
SQL that updates only `password_hash` and `gjp2_hash`. After a database backup,
execute it through phpMyAdmin in the existing native database. The script
makes no network or database calls. Never share your password or saved hashes
in chat, logs or tickets. Check the account is yours before changing it.

Administrator registry and stored-backup regressions: `tests/compat/php7-native-admin-registry.php --mysql` runs on PHP 7.0 and 8.3. It checks idempotent legacy import, preserved factors, panel-only identity separation, invitation expiry/use, permissions, role assignment/deletion and session revision; it restores a compressed SQL archive into a disposable database and checks binary data and views.
