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
| Levels | Public search/list, author search (type 5), recent (type 2), downloads up to 8 MiB, authenticated upload/update up to 200 KiB |
| Users | Public profiles/search/top/creator rankings, authenticated public browsing, score/appearance updates returning legacy userID |
| Comments | Local level comments, own profile posts, history, pagination, recent/likes ordering, UTF-8 text, GD 2.209 exact timestamps |
| Votes | Authenticated likes/dislikes for levels and both comment kinds; duplicate requests are idempotent |
| Friends | Authenticated friend requests, incoming/sent pages, accept/ignore/cancel, friend lists and removal (optional, new local tables) |
| Saves | Local database backup/sync up to 8 MiB, byte preservation and SHA-256 integrity; account ID or username authentication |
| News | Native GZIP/Base64URL GD 2.209 text/link news, version file, custom content URL discovery; no MySQL dependency |
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
3. In phpMyAdmin select the **same existing native database** and import
   `mc/compat/php7/features.sql`. It uses only `CREATE TABLE IF NOT EXISTS`
   for the additive native tables. It neither alters nor clears existing tables.
   It can be imported again. No installer reset or database recreation.
4. Keep the root routing rules described below. `/mc/...` paths now have
   their installation prefix removed before endpoint matching.
5. Account processing still needs `enable_accounts => true` in the private
   configuration. Registration separately needs `enable_registration => true`.
   Mutations need `enable_writes => true`. Friend routes also need
   `enable_social => true`. Backup/sync additionally needs
   `enable_cloudsave => true`. The archive leaves all these switches off.
6. For saves configure PHP `post_max_size` to at least `26M` and
   `memory_limit` to at least `128M`. A URL-encoded 8 MiB save can approach
   24 MiB on the wire. The accepted decoded save limit remains 8 MiB.
   A host's lower POST/proxy limit can still reject a request before PHP runs.

Example feature switches in the **existing private** config (retain DB fields
and the installer-generated auth pepper):

```php
'enable_accounts' => true,
'enable_registration' => true,
'enable_social' => true,
'enable_writes' => true,
'enable_cloudsave' => true,
```

## New installation

The native archive contains `public/php7-setup.php`. Its existing setup flow
requires HTTPS, a private strong `config/php7-setup-key.txt`, a session CSRF
check and an empty database. It initializes the base schema plus the new
feature tables, then writes private `config/php7-local.php`. It refuses an
existing or partially initialized native schema. MySQL DDL cannot roll back;
do not use first-time setup for an upgrade.

Create the empty `public/php7-mode.on` marker to enable routing after setup.
Delete `public/php7-setup.php` after first installation. Keep `config/` and
`compat/` private via the included Apache guards. `/install.php` is still the
PHP8 installer and is not the PHP7 setup path.

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
```

Keep other root rules. An HTTP 404 at `/a/...` indicates routing must be
checked. An adapter HTTP 503 indicates config, extension or schema failure;
it does not prove a protocol error. Check PHP logs without publishing secrets.
The adapter's browser `/mc/health` checks native DB connectivity.

## News configuration

Copy `compat/php7/news.example.json` to private `config/php7-news.json`.
Set `enable_native_news => true` and `site_url` to the HTTPS **origin** of
your own host, e.g. `https://your-gdps.5v.pl`, without `/mc`.
Increment each post's version above 1,000,000 after editing.

- POST `/a/getCustomContentURL.php` returns the origin plus `/gd-content`.
- `/gd-content/news/news_version.txt` returns the integer version.
- `/gd-content/news/news.dat` returns the native GD payload.
- `/news/*` aliases are also available for diagnostics.

News does not require MySQL. Image/video elements and safe music/SFX CDN
routing are not implemented by this native milestone.

## Still pending in the PHP7 port

Native administration/moderation, private messages, blocking and mute lists,
cloud-save migration/encryption/recovery parity, password recovery,
full profile appearance/settings, daily quests/rewards, clans, level lists,
2.209 threads/following and sanctions, plugin/migration parity, advanced
private/permissioned searches/downloads and large editor uploads.
Friend data is stored in `php7_friend_requests` and `php7_friendships`, independent
of PHP8 or Cvolton tables. Friend requests support only the implemented privacy
model; user blocks and per-account request privacy settings are not yet portable.
Unknown routes return `-1`; unsupported/private features are not simulated.
Track the overall port in issue #148.

## Verification

The native source and pure routing/encoding tests run on **PHP 7.0.33**.
Disposable MariaDB tests cover registration/login, native level protocol,
public users, comments/history, duplicate and reversed votes, owner checks,
private-level visibility, client scores and cloud-save integrity/isolation.
PHP8 tests compare existing encoders byte-for-byte. Provider FTP deployment,
Apache configuration and actual GD 2.209 client acceptance must still be
verified on the target host.

```sh
php tests/compat/php7-native-features-pure.php
TEST_DB_ROOT_PASSWORD=... php tests/compat/php7-native-mysql.php
TEST_DB_ROOT_PASSWORD=... php tests/compat/php7-native-features-mysql.php
```

Both MySQL tests create and remove their own fixed disposable `*_ci`
databases; never grant that test runner credentials to a production database.

## Termux: recover a TEST account password without database access

On Android Termux run `pkg install php curl` and retrieve
`tools/mobile/php7-account-reset.php` from this repository, then run
`php php7-account-reset.php 2 izk` (substitute your test account ID/name).
The program prompts for the new password twice with input hidden and prints
SQL that updates only `password_hash` and `gjp2_hash`. After a database backup,
execute it through phpMyAdmin in the existing native database. The script
makes no network or database calls. Never share your password or saved hashes
in chat, logs or tickets. Check the account is yours before changing it.
