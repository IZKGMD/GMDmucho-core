# MuchoCore custom songs: version and delivery gates

## Capability boundaries

The stock Geometry Dash client first introduced Newgrounds/custom-song selection in **GD 1.9**. Before 1.9, native server-side song metadata cannot create a missing editor feature; GD 1.0–1.8 needs a separately built, version-specific client modification. This is a documented limitation, **not** a pass.

| Client | Native custom-song editor | MuchoCore PHP 8 server contract | Required release evidence |
| --- | --- | --- | --- |
| 1.0–1.8 | No | Do not advertise unsupported song selection | Client patch feasibility and music playback in a real patched build |
| 1.9 | Yes | `getGJSongInfo`, encoded URL, verified song ID and MP3 static delivery | Real 1.9: choose ID, download, save, reopen, play |
| 2.0 | Yes | Same song wire and level-list embedding | Real 2.0: choose ID, download and play |
| 2.1 | Yes | Same song wire and level-list embedding | Real 2.1: choose ID, download and play |
| 2.2–2.209 | Yes | Same song wire; 2.209 also has `getGJAudioFileSize` | Real PC and Android where supported, including 2.209 binaryVersion 51 |

## Present PHP 8 behavior

- Owner/admin uploads and player uploads store an MP3 in `storage/music-public` with a random filename, create a row in `songs`, and expose a URL through the dedicated Caddy `/music/` file server. User uploads require moderation.
- A verified song's metadata is returned through `/database/getGJSongInfo.php`; unknown or unverified IDs return `-1`.
- A level upload stores its `songID`; a level list embeds metadata only for verified songs. Embedded and single-song metadata must use the same serializer, escaped download link, and enabled/disabled flag.
- The static download route must return original MP3 bytes, `audio/mpeg`, and valid HTTP Range responses for clients with resumable downloads. The acceptance suite generates a one-second original sine-wave MP3 and checks it with `ffprobe` before serving it.
- The game client, not the server, finally downloads, caches, and decodes the file. Successful protocol/HTTP tests alone do **not** certify actual audio playback.

## Risks to test before claiming universal support

1. Native client patching must redirect *every relevant song endpoint* for the exact Windows/Android version, not only game-account URLs.
2. Verify whether an older platform can fetch the configured HTTPS song URL, follow redirects, and decode MP3 frames; document any required client patch.
3. Test a song ID from an administrator upload and a moderated player upload, then test a level containing that ID and re-download from a fresh client install.
4. Test unknown, deleted and unverified IDs; moderation hides metadata but static URLs may remain retrievable until the file is removed.
5. Verify storage permissions, file persistence after upgrades, backups and restore; SQL metadata alone is not a playable music backup.
6. Verify rights and authorization to distribute uploaded recordings. Avoid silently proxying copyright-restricted music or bypassing Newgrounds permissions.

## Verification commands

The CI stages are:
- `php tests/protocol/song-version-matrix.php`
- `python3 tests/integration/song-delivery-http.py` under the **isolated** PHP 8 HTTP acceptance stack

The second script refuses to run unless GitHub Actions explicitly configures the disposable `testgdps` database and local HTTP origin. CI is a first gate; each real-client pass must be marked separately with a screenshot/log or captured request and observed audio playback.
