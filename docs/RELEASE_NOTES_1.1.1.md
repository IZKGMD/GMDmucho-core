# MuchoCore 1.1.1 — Your GDPS. Your Rules. 🚀

**The new MuchoCore is ready to download and install.** This is the first
publicly installable **PHP 8.2+** release from the latest GD 2.209 development
line, published by **IZK**. Free, open-source, self-hosted, MIT licensed.

> **Release scope:** PHP 8.2+ server and installer. GD 2.209 extensions are
> still partly under active compatibility verification. This is NOT a
> statement that every inventoried endpoint passed real-client certification.
> **PHP 7 / 5v.pl is not part of this release**: it will be ported and tested
> separately, against the immutable 1.1.1 source tag.

## ⚡ What you get

- **A complete server foundation**: game registration/login, profiles,
  level upload/download/search, comments, account progress Cloud Save,
  rankings, music and a web administration panel.
- **A more reliable GD 2.209 social backend**: private messages, direct and
  group threads, invitation rights, read/unread status, search and history
  isolation, follow notifications, user information and moderator paths.
- **More correct protocol responses**: message Base64/XOR round-trips, strict
  identities, scoreboards without N+1 account queries, 2.209 notification
  wire, server-side validation and safer malformed-input handling.
- **Encrypted Cloud Save** with idempotent revisions, bounded history and
  preservation of the existing encryption key during updates.
- **Operator tools**: browser admin, first-owner setup, database migrations,
  backup/restore, client address patcher and configurable server settings.
- **Automated quality checks**: PHP 8.2–8.5 CI, MariaDB integration, real
  PHP-FPM/Caddy HTTP acceptance and an emulator sending authentic *shapes* of
  all 113 statically recovered Windows GD 2.209 endpoint URLs. The expanded
  authenticated HTTP replay confirms notifications and a two-player DM
  lifecycle, including SQL persistence and per-user clear/read state.

No proprietary Geometry Dash EXE/APK files are distributed with MuchoCore.
Use your own authorized game client when preparing a server-specific client.

## 📦 Which file should I download?

**For PHP 8.2+ shared hosting with MySQL/MariaDB:**

**\`MuchoCore-v1.1.1-shared-hosting.zip\`**

This is the **ready-to-upload** distribution. It contains
\`vendor/autoload.php\`, the browser installer, migrations and server code.
GitHub's automatic "Source code (zip)" archive is NOT a substitute.

1. Create an empty MySQL/MariaDB database and database user in your hosting
   panel. Enable PHP 8.2+ (PHP 8.3 recommended), PDO MySQL, mbstring, OpenSSL,
   ZIP and HTTPS.
2. Unzip the release file. Upload the **contents** of its \`muchocore/\`
   directory to your site's web root, preserving \`.htaccess\`, \`config/\`,
   \`public/\`, \`vendor/\` and all other files.
3. Visit **\`https://YOUR-DOMAIN/install.php\`** and follow the host checks.
   Set database connection and first administrator credentials.
4. Open **\`https://YOUR-DOMAIN/admin/\`** and check **\`/health\`**.
5. In admin **More tools → Clients & extensions → Web Client Patcher**,
   point your own Geometry Dash client at the GDPS. Test registration,
   level upload/download and Cloud Save before inviting users.

Details: [shared hosting](docs/SHARED_HOSTING.md) ·
[client setup](docs/CLIENT_SETUP.md) ·
[Russian start guide](START_HERE.md).

**For an Ubuntu Linux VPS and a domain:**

The standard installer selects the latest **published stable** release and
pins its Git tag instead of installing whatever happens to be on \`main\`:

\`\`\`bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install-remote.sh | sudo bash
\`\`\`

Enter your first administrator credentials, domain and server name when
prompted. Direct HTTPS on ports 80/443 is the default; Cloudflare credentials
are not required.

See [VPS setup](docs/VPS_INSTALL_QUICK_RU.md).

## 🔄 Updating an existing MuchoCore installation

**Back up the database, .env/configuration, uploads and the exact existing
Cloud Save encryption key first.** Never reset the key to "fix" save errors.
For VPS upgrades run:

\`\`\`bash
sudo mucho update
sudo mucho doctor
\`\`\`

Use the [shared-hosting update guide](docs/SHARED_HOSTING.md) for FTP hosting.
Do not run first-install wizard over a live installation. Test schema migrations
on staging before upgrading a populated production GDPS.

## ✅ Verified vs. still being completed

**Verified automatically:** core positive HTTP flows, authenticated user and
level actions, 2.209 Cloud Save, follow notifications and claim rights,
two-account DM send/reply/read/clear/search with SQL persistence, malicious
and missing-auth request guards, route response hygiene and package contents.

**Previously checked interactively in the real GD 2.209 client:** selected
message and thread functionality by the project maintainer. The project does
not claim a comprehensive published packet capture for every 2.209 feature.

**Still open:** all 166 catalogued endpoint contracts are not fully
certified; 160 currently remain inventory-only under the project's strict
8-dimension release audit. This counts **certification coverage**, not the
number of broken handlers. Some new 2.209 moderation/news/editor response
fields are provisional until validated with real client traces. Support is
not guaranteed on hosts that block programmatic game traffic.

**PHP 7 and 5v.pl:** the existing development preview is distinct from this
release. A dedicated 1.1.1 native PHP 7 port will be evaluated separately,
file-by-file and endpoint-by-endpoint, without silently reusing PHP 8-only
syntax or calling a partial PHP 7 build stable.

## 🔎 Verify the download

The release contains \`checksums.sha256\`. On Linux, put it beside the ZIP
archives and run:

\`\`\`bash
sha256sum -c checksums.sha256
\`\`\`

**Report issues:** https://github.com/IZKGMD/GMDmucho-core/issues
(share your host/PHP version and action attempted, **never passwords or keys**).

**Source:** https://github.com/IZKGMD/GMDmucho-core · MIT · IZK © 2026.
