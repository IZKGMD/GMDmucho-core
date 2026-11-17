# MuchoCore

Create your own Geometry Dash private server: accounts, levels, music, Cloud Save and a web admin panel.

[С чего начать — по-русски](START_HERE.md) · [Download](https://github.com/IZKGMD/GMDmucho-core/releases) · [Website](https://muchogdps.space/muchocore/) · [Report a problem](https://github.com/IZKGMD/GMDmucho-core/issues)

## Install

Use **PHP 8.2+** for a new server. Choose the row that matches your hosting:

| What you have | Where to start |
| --- | --- |
| Hosting with PHP 8.2+ and MySQL | Download the **shared-hosting ZIP** from [Releases](https://github.com/IZKGMD/GMDmucho-core/releases), upload its contents and open `/install.php`. [Step-by-step guide](docs/SHARED_HOSTING.md) |
| A Linux VPS and a domain | Point the domain to the VPS IP, then run the command below. PHP and the database are installed automatically. [VPS guide](docs/VPS_INSTALL_QUICK_RU.md) |
| A 5v.pl site with PHP 7 | Use the separate **PHP7 native preview**. The PHP 8 archive will not run there. [5v.pl guide](docs/PHP7_NATIVE_PHASE1.md) |

VPS installation:

```bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install-remote.sh | sudo bash
```

The installer selects a published stable release. Enter your administrator login and password, server name and domain when asked. Sign in at `https://YOUR-DOMAIN/admin/` with the credentials you chose.

Direct VPS HTTPS remains the default. No Cloudflare API token, Global API Key, Cloudflare Tunnel or Cloudflare Proxy is required for this path.

For shared hosting, download `MuchoCore-vX.Y.Z-shared-hosting.zip`, **not GitHub's source-code ZIP**. The shared package includes the required dependencies. Create an empty database in your hosting panel before opening the installer.

## Start playing

1. Open the admin panel.
2. Open **More tools → Clients & extensions → Web Client Patcher** to create a client for your server. Keep your original game file. [Windows and Android guide](docs/CLIENT_SETUP.md)
3. Launch the patched game, create a game account, then upload and download a test level. Your website administrator login is separate from your game account.
4. Set up player downloads under **More tools → Clients & extensions → Client Downloads** before inviting friends. Publish only files you have permission to distribute.

Use the admin panel for players, levels, music and server settings. Additional technical tools are under **More tools**.

## Update an existing server

**Use the updater, not first-time installation.** Keep a backup of your database, settings and Cloud Save key before updating.

On a VPS:

```bash
sudo mucho update
sudo mucho doctor
```

For other hosting: [PHP 8 shared updates](docs/SHARED_HOSTING.md) · [5v.pl updates](docs/PHP7_NATIVE_PHASE1.md).

## Need help?

| Problem | First step |
| --- | --- |
| Installation cannot continue | Fix the failed hosting checks shown by the installer. |
| The website opens, but the game cannot connect | Check the client address and hosting routing using the [client guide](docs/CLIENT_SETUP.md). A working website alone does not confirm game connectivity. |
| Login or level downloads fail | Include the client version and failing action in an [issue](https://github.com/IZKGMD/GMDmucho-core/issues). Never include passwords. |
| Moving an existing GDPS | Install into a fresh database, then use [Migration Center](docs/MIGRATION_CENTER.md). |

GD 2.209 support is still under verification; complete real-client acceptance is **not certified**. The PHP7 build is a development preview. [Compatibility status](docs/CLIENT_COMPATIBILITY.md)

The source version is recorded in [VERSION](VERSION). A source version is not proof of a published stable release. Use [Releases](https://github.com/IZKGMD/GMDmucho-core/releases) for published builds; `main` contains ongoing development.

## More information

[Changelog](CHANGELOG.md) · [Local patchers](patchers/README.md) · [Plugins](docs/CUSTOM_PLUGINS.md) · [Advanced networking](docs/ADVANCED.md) · [API](openapi.yaml) · [Go edition (in development)](go/README.md)

Source code is in `src/`, HTTP entry points in `public/`, and checks in `tests/`. See [tests/README.md](tests/README.md) for development validation.

MuchoCore and MuchoProtect are developed by **IZK © 2026**, with contributor and third-party rights respected. Source code: [MIT](LICENSE). Geometry Dash and its assets belong to their respective owners. [Website terms](https://muchogdps.space/legal/terms/) do not override the source-code license.
