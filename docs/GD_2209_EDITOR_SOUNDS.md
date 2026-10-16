# GD 2.209 editor sound uploads

These five GD 2.209 editor sound routes have **real server-side** backing:

- `getGJEditorSounds.php`: public paginated library metadata, optionally by creator.
- `uploadGJEditorSound.php`: authenticated upload with bounded Base64 audio.
- `downloadGJEditorSound.php`: returns Base64URL audio bytes (provisional wire).
- `deleteGJEditorSound.php`: creator-only deletion.
- `updateGJSoundDesc.php`: creator-only description changes.

## Limits and safety

Original bytes are stored as `MEDIUMBLOB` in a database with account FK and
unique `(account_id,sha256)`. There is **no publicly writable filesystem
path**. Uploads are restricted to recognized WAV, OGG and MP3 signatures
and to **2 MiB per file**, **20 MiB per account**, **50 files per account**.
The account row is locked while deciding quota to prevent concurrent upload
races. Identical owner files are reused rather than duplicated.
Only metadata and encoded audio bytes are returned; no executable media.

The service validates real GJP credentials for all write operations, excludes
banned and deactivated creators from public discovery, and deletes stored
audio when the owner account is deleted.

## Native GD 2.209 status

Audio formats and HTTP data fields (Base64 `data`/`audio`) are provisional.
The server does not yet know the exact 2.209 upload multipart encoding,
response key order, or the official editor audio pack framing. In particular,
these routes should **not** be described as confirmed native-client parity
until redacted 2.209 network captures are used as golden protocol fixtures.

Integration tests in `tests/application/gd2209-audio-integration.php`
exercise MariaDB storage, media rejection, quotas, ownership checks,
banned-user filtering, and cascading deletion. They run automatically in CI.

## Operator resource impact

Editor files reside in MariaDB and therefore grow database backups.
Before enabling upload for large communities, operators should separately
configure storage quotas, backup capacity, monitoring and content moderation.
