# MuchoClient 0.9.4 — one file for all modules


Emoji Support renders 4009 bundled Twemoji 17 images in ordinary bitmap labels,
plain multiline comments/descriptions and Mucho UI. Whole UTF-8 sequences map to
one native layout slot: flags, skin tones, keycaps and ZWJ families are supported.
Original text is retained for getters, copying and protocol transmission. Free-text
inputs temporarily allow bytes belonging to recognized emoji; numeric/password
filters stay active. The Emoji button copies 48 common choices for pasting.

Emoji pages are loaded on demand from the package (16 pages of 512x512, at most
16 MiB of RGBA textures if every page is used). ASCII labels retain the native
path. Parsing happens on text changes, with at most 128 image sprites per label
and a 16 KiB rich-label limit; there is no emoji network traffic or polling.
Native rich-text descriptors and batched level-text objects retain their original
rendering. This is not a replacement for a general Unicode text engine. Device
rendering, keyboard behavior and frame times still require in-game verification.
Disable Emoji Support in Geode settings and restart to return to native text.
Graphics attribution and CC BY 4.0 license are included in the package.

Smooth UI Animations is enabled by default in Geode settings. Mucho windows
open/close with short scale transitions; module buttons, ranking rows, clan
activity and color choices appear with a bounded stagger. Profile art fades
in after loading. Clan and Daily Quest progress bars interpolate inside their
tracks. Profile numbers and Quest XP count up with at most 13 glyph updates per
transition, independent of display FPS. A newly confirmed Daily Quest claim
shows a short XP/star effect; retries do not replay that reward animation.

Disable Smooth UI Animations to use immediate transitions, or enable Reduced
Motion for gentle fades and progress updates without zoom, movement, animated
counters or reward particles. Extra effects are suppressed when a PlayLayer
exists. There is no animation-specific network polling or permanent update
loop: Cocos actions stop with their views, loading effects stop with requests,
and the existing 0.9.2 shared asset queue, caches and one-second quest timer
remain in place. Actual device frame times have not been measured.

Daily Quests uses a compact 420x300 panel and scales to the available screen,
including room for its close button. Quest rows, claim controls and footer have
separate reserved areas. Player rankings fit clan prefixes and nicknames before
the first statistic, keeping their original left edge and matching hit area.
Uploaded MuchoCore avatars replace native ranking icons after decoding; missing
or unavailable images retain the normal GD icon. Public metadata is cached for
60 seconds (at most 128 accounts), and decoded textures use a shared bounded cache.
This interface update requires no additional server migration after 0.9.0.

Install **one** `izkgmd.muchoclient.geode`, replacing the previous MuchoClient
package with the same mod id. It includes Daily Quests, clans and clan quests,
leaderboards, colors, profiles, avatars and paginated server-plugin navigation.
The combined package carries native builds for Windows x64 and both Android
architectures. It does not require Python, separate feature mods or sidecar DLLs.

Daily Quests requires the matching MuchoCore server update and database migration.
The player earns new stars, moons and demons; the server tracks accepted profile
sync deltas and exposes three rotating daily goals. The **Claim** button adds
Quest XP once. Each 250 Quest XP adds a Quest Level. Quest XP is separate from GD
diamonds, stars, orbs and coins. Progress updates when the game's normal profile
score sync reaches the server, not through a client-supplied completion flag.

All days reset at 00:00 UTC. The countdown uses the server's remaining time and
the client's monotonic elapsed time. A timeout can be retried without awarding
the same reward twice. Account/server changes discard pending personal requests.

See [server setup](../../../docs/DAILY_QUESTS.md) for flags, routes and limits.

# MuchoClient — one Geode mod for server extensions (v0.8.4)

MuchoClient connects Geometry Dash 2.2081 (Windows and Android) to MuchoCore.
Players install **one .geode file**. Server-side PHP plugins publish safe,
validated feature metadata in MuchoCore's manifest; MuchoClient renders
the extra modules as buttons inside a Geometry Dash popup.

The first demo plugin is at `examples/muchoclient-welcome/`.
The user can click **Welcome** to fetch a read-only JSON message from
`/extensions/welcome`. Only trusted PHP runs on the server — the native
client does not download or execute plugin code.

## Build and use

- GitHub Actions builds Win64, Android ARM64 and ARMv7, and packages them
  in one cross-platform .geode.
- Install through Geode on Geometry Dash 2.2081, then press **Mucho** on the
  game main menu.
- The default server is `https://muchogdps.space`. To use a different server,
  edit the `MuchoCore Server URL` Geode setting (HTTPS origin, without
  `/database`).
- The client GETs `/muchoclient/manifest`, validates the feature catalog, and
  POSTs `/muchoclient/negotiate` with `client_version=0.9.3&protocol=1`.
- Compatible clients display a popup of feature buttons. Public extension
  endpoints under `/extensions/` return constrained JSON text.

## VPS diagnosis

After updating the MuchoCore VPS:

```bash
cd /opt/mucho-core
sudo mucho client-doctor
curl -sS -i https://muchogdps.space/muchoclient/manifest
curl -sS -i https://muchogdps.space/extensions/welcome
```

`sudo mucho client-doctor` probes the PHP application directly inside Docker,
including its database connection, plugin registry, and client handshake.
It does **not** prove that Caddy, Cloudflare, DNS or the public HTTPS endpoint
works. Compare the CLI test with the two HTTPS curl results.

To install the first demo plugin on the server, copy
`examples/muchoclient-welcome` into `custom/plugins/muchoclient-welcome`
on the VPS. The plugin directory must be mounted inside the app container.
See the example's README for detailed steps.

## Security and compatibility

- MuchoClient does not execute remote Lua/JS/PHP/C++ or install new .geode
  binaries. Server responses are data only.
- The feature manifest is public metadata, **not player authentication**.
  Each privileged feature must independently validate the player session.
- Feature paths are limited to trusted relative `/extensions/...` paths.
  Arbitrary external URLs and redirects are rejected.
- The single companion supports GD 2.2081 with Geode, not all legacy GD
  binaries. Standard GD endpoints remain independent of MuchoClient.
- Real-device runtime testing is still required; successful native CI
  compilation does not guarantee a running VPS or installed game behavior.

## Clans

The Clans window uses the current Geometry Dash account ID and GJP2, with
server authorization for every operation. It supports browsing and paged
search, creating open or invite-only clans, joining, leaving, complete member
lists, incoming invitations, and owner/officer management:

- invite by account ID, list outgoing invitations, revoke invitations;
- promote/demote officers, kick members, ban with a reason, list/remove bans;
- edit name, tag, description, member limit and joining mode;
- transfer ownership and disband with explicit confirmation.

Owners cannot leave until ownership is transferred. Officers cannot moderate
owners or other officers. The server rechecks these permissions inside the
mutation transactions. The client preserves failed form input, prevents
concurrent button requests, and cancels callbacks when its popup closes.
All buttons set the game's animation baseline to their intended scale.

Update both the mod and MuchoCore for outgoing invitations and server-paged
search. Existing v0.3 clients remain compatible with the extended JSON API.
Run the clan integration tests against an isolated MariaDB; CI builds the
three native targets and combines them only after those tests pass.

### Validation

```bash
g++ -std=c++20 -Wall -Wextra -Werror tests/client/muchoclient-clan-rules.cpp -o /tmp/clan-rules
/tmp/clan-rules
TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3306 TEST_DB_ROOT_PASSWORD=YOUR_TEST_PASSWORD \
  php tests/application/clan-client-integration.php
```

The integration test creates and drops a randomly named test database. Never
point it at production. Native compilation and these API tests do not replace
verification inside the installed game on Windows and Android.

## Clan leaderboards

The **CLANS** button above Scores in the Create / Saved / Scores menu opens
public clan rankings. Tabs rank current member totals for stars, demons, moons,
diamonds, user coins, secret coins and creator points. Each row shows its global
rank, clan name/tag, membership count and total; click it to open the clan.
The current account's clan is highlighted and its rank is available even when
it is outside the selected page. Viewing rankings does not require login;
opening clan management still uses the authenticated game account.

The core serves `POST /api/clans/leaderboard` with `metric` (default `stars`),
`offset`, `limit` (1–50), and optional `accountID` for the public own-clan rank.
Totals include current members only, exclude banned/inactive accounts, and
use stored MuchoCore profiles. Joining, leaving and profile updates affect the
next refresh. Equal totals use clan ID order, matching sequential player ranks.
There is no invented combined score or new database migration. Update both
the mod and core. Large totals use 64-bit numbers. Native game testing remains
necessary after CI compilation and MariaDB integration tests.

## Colors

Open **Mucho > Colors**, or **My Clan > Colors**. Choose **Nickname** to customize your own name. Clan owners can select **Clan prefix** to set the shared `[TAG]` color. Twelve swatches and six-digit HEX input update a live preview. Press **Save** to persist both settings; **Default** restores the normal nickname color or the golden clan prefix after saving.

Colors appear to clients running v0.8.4 in profiles, comments and player rankings, and in native clan pages. Vanilla clients and older MuchoClient versions keep compatible text prefixes. Login names and authentication are unchanged. Public appearance is loaded in batches of at most 50 accounts and cached for 60 seconds; failed lookups retry after 30 seconds when a view is loaded again.

The server migration `20261010_001_appearance.php` creates two small tables. Colors survive restarts. Nickname colors belong to the account; clan prefix colors belong to the clan and its current owner. Leaving or disbanding removes the prefix but preserves nickname color.

## Clan quests and activity

In **Mucho > Clans > My Clan**, open **Quests** or **Activity**. Owners and officers can start a seven-day goal: 100–100000 stars or 5–1000 demons. One active quest per metric is allowed; the last 20 quests are displayed. Completed quests retain their contribution rankings. Cancelling needs confirmation and stops further progress.

Only growth in the server's authenticated player statistics counts. Old totals at quest creation and at joining are snapshotted; duplicate updates and score resets cannot earn the same points twice. The game must sync player statistics to MuchoCore, as it does for normal player leaderboards. Departure freezes that player's contribution; rejoining baselines the current score while preserving prior earnings. Quests are cooperative goals and do not grant game currency.

Activity is visible to current clan members. It records creation, joining, leaving, kicks/bans, roles, owner transfer, settings and quest outcomes. Both activity and contribution lists have server pagination. Banned and inactive accounts cannot contribute.

Update MuchoCore from `main` and run `php bin/migrate.php migrate` before using these pages. The five new APIs require POST and an authenticated current clan member:

- `/api/clans/quests`
- `/api/clans/quests/create` (`metric`, `target`; owner/officer)
- `/api/clans/quests/cancel` (`questID`; owner/officer)
- `/api/clans/quests/contributions` (`questID`, `offset`, `limit`)
- `/api/clans/activity` (`offset`, `limit`)

## Avatars and profile covers v0.8.4

Open **Mucho > Profile**, or the **Art** button beside the username in a regular GD profile. The public card shows the avatar, cover, colored name/clan tag and server statistics. A saved avatar and dimmed cover strip also appear behind the normal profile header. Default GD icons, comments and statistics remain available.

The card keeps the full 3:1 cover above a separate avatar/name row. Six statistics share one evenly spaced row; the native GD strip is constrained to the actual profile background rather than screen coordinates. v0.8.4 needs no new migration after the v0.8.0 server update.

The main menu's round profile button below your username displays your uploaded avatar, clipped to its inner circle. Its native rim, size, position, hit area and profile-opening action remain intact. The ordinary profile icon stays visible while loading and when no image is available. Saving or removing your avatar refreshes the button immediately; account/server changes reset it and stale responses cannot apply to another session. Public metadata refreshes every 60 seconds (30-second retry after failure), using the existing bounded image cache.

Your own card has **Avatar**, **Cover** and **Colors** buttons. Choose a local JPG/PNG with the system picker. The full photo shows a crop rectangle with dimmed surroundings and a thirds grid. Drag the rectangle to move it, drag a corner to resize it, or tap the photo to recenter the selection. Covers keep a fixed **3:1** area and save at **960x320 pixels**; avatars use **1:1** and **256x256 pixels**. These dimensions are displayed above the selection view. The separate preview shows the chosen area live. **Left/Up/Down/Right**, **Zoom**, **Rotate** and **Reset** provide additional control, then press **Save**. Selection and crop changes are previews until saved. **Remove** clears only the selected slot. Source EXIF orientation is not applied automatically; Rotate corrects sideways photos in the preview.

On Windows, the zoom slider adjusts **100–300%** continuously in 1% steps. Hover the photo and scroll the mouse wheel to zoom in 5% steps. **Arrow keys** move the selection precisely; **Shift + arrows** moves faster. **+/-** zoom, **R** rotates clockwise and **0** resets. Zoom and rotation keep the selected source point centred when the photo edges allow it. The editor shows the selected source area in pixels; the output sizes stay 960x320 or 256x256. Open the info button for all controls.

Save is enabled once the selected photo has decoded. Closing an unsaved selection asks whether to keep editing or discard it. Help, removal and close dialogs own input, so scrolling or pressing shortcuts cannot change the image behind them. While an upload is running, wait for its result before closing.

Inputs are limited to 4 MiB, 12 megapixels and 8192 pixels per dimension, with a minimum side of 16 pixels. The server crops/resizes to 256x256 avatars or 960x320 covers and re-encodes JPEG at quality 88, flattening transparent pixels against the profile background and removing source metadata. Each output is at most 512 KiB. The database retains only the current image per slot, so normal database backups include the images.

Public reads never use player credentials. Image downloads accept only a kind and exact SHA-256 version from this server's profile metadata, not arbitrary image URLs. Decoded textures are shared between views, limited to 32 entries / 8 MiB and isolated by server origin. Public profile metadata is shared across avatar views. Each queue permits two simultaneous requests; identical image keys download/decode once. File reads and Base64 preparation run asynchronously; uploaded-photo previews use Geode LazySprite; stored avatars/covers decode in the worker pool and upload to the GPU through a bounded menu-only queue. Closed windows cancel their requests and account/server changes prevent writes from a stale editor.

Update the core first:

```bash
sudo env MUCHO_UPDATE_CHANNEL=main /opt/mucho-core/update.sh
```

The updater rebuilds the Docker app image with PHP GD and applies `20261011_001_profile_media.php`. On shared hosting enable PHP GD with JPEG/PNG support and run the migration. v0.7.0 and older clients keep their previous name-color and clan APIs.

New POST endpoints:

- `/api/profile/get`: public `accountID`; colors, statistics and media metadata.
- `/api/profile/image`: public `accountID`, `kind`, `version`; exact JPEG bytes or 404.
- `/api/profile/upload`: authenticated `accountID`, `gameVersion`, `gjp2`, `kind`, standard padded Base64 `imageData`; optional `cropX`/`cropY` (0–1000), `zoom` (100–300), `rotation` (0/90/180/270).
- `/api/profile/remove`: authenticated `accountID`, `gameVersion`, `gjp2`, `kind`.

`kind` is `avatar` or `banner`. Server/CI validation is in `tests/application/profile-images.php`, `tests/application/clan-client-integration.php` and `tests/client/muchoclient-clan-rules.cpp`. Daily Quest countdown text updates once a second; cached name colors repaint only when their appearance revision changes. Decorative processing is deferred while a PlayLayer exists, including while paused.

Native UI layout/file picker behavior still needs verification in the actual game on a device.


