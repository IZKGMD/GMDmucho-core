# GD 2.209 level death data and platformer controls

This change implements the *server-side behavior* for five previously missing
GD 2.209 routes. Field names and native response framing are provisional;
full client interoperability is **not** claimed without captured real traffic.

| Endpoint | Implemented on MuchoCore |
|---|---|
| `getGJDeathData.php` | Public (listed-only) 256-cell aggregate death marker heatmap for the latest level version |
| `uploadGJDeathData.php` | Authenticated per-account samples, bounded positions and counts |
| `getGJLevelControls.php` | Stored custom control data for an existing nondeleted level |
| `uploadGJLevelControls.php` | Owner-only create/update; max 16 KB UTF-8 |
| `deleteGJLevelControls.php` | Owner-only deletion |

Death coordinates use a tenth-unit quantization to avoid high-cardinality
floating point keys. Per account, 500 distinct positions per level/revision
can be stored with at most 100 counted deaths per position. Aggregated reads
combine contributions across accounts, but return only aggregated positions,
not uploader identities. The current revision uses the level's stored
`level_version` field; older samples are preserved when it changes.
Unlisted and deleted levels do not publicly expose death locations.

Control settings are stored *as opaque text*: the server enforces ownership
and size but does not invent semantics for the client's unpublished internal
control-string format. Similarly, the provisional death response emits
`percentBasisPoints,xBucket,yBucket,count` rows separated with `|`, pending
a real GD 2.209 capture.

The `tests/application/gd2209-level-integration.php` CI test uses a
temporary isolated MariaDB and verifies data aggregation, revision isolation,
owner rights, invalid inputs, deleted/unlisted policy and cascading cleanup.

Before claiming native compatibility, capture redacted requests and response
examples from GD 2.209 for all five endpoints and update the serializer to
match observed order, field names and encodings. No account credentials or
private saves should be included in the fixture.
