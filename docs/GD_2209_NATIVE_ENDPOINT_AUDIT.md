# Native EXE endpoint audit

This is static reverse-engineering and synthetic HTTP/SQL evidence. It does
not certify Windows startup, gameplay, UI rendering or production hosting.
No original EXE, account credential or player data is checked into this audit.

## Exact supplied build

* File: `GeometryDash(5).exe`, Windows x64 PE, 13,178,880 bytes.
* SHA256: `4eee06d5c2e2213b4ba2b1f6233dd426bbdd0e9b29d7c375e499516b6f8f69d5`.
* `.rdata` contains 113 direct PHP URLs and two cloud-account URL templates.
* Reproduce: `python3 tools/client/native-exe-inventory.py /path/to/original.exe`.
* The report compares actual embedded paths with the current Application and
  Router, including aliases, the shared recovery handler and the known
  `/databas/checkIfServerOnline.php` typo repaired by the patcher.

## Implemented native gaps

| Operation | Native evidence in this build | Server behavior |
| --- | --- | --- |
| `adminQueueMute` | Request at image VA `0x1400aab7f`, action ID 122; `adminQueueMute_%i`; shared admin builder `0x1402412b0` appends `targetAccountID` and credentials | Authenticated elder/owner restriction of kind `queue`, audited as `QUEUE_MUTE`; filter both queue records and total without deleting reports or banning the player |
| `adminQueueUnmute` | Request in the same admin popup, action ID 123; `adminQueueUnmute_%i` | Remove the existing queue restriction and audit `QUEUE_UNMUTE`; retained reports reappear |
| `getLevelRateInfo` | Request `0x1402538d0`, response dispatcher `0x140253c10`, delegate `0x14041b780`, RateInfoPopup parser `0x14041a490` | Two colon-separated histograms: ten comma-separated star counts and five quality counts (F/E/L/M/R); count the latest persisted advisor suggestion per account |
| `getSaveData` | Request `0x14022bfa0`, response parser `0x14022c290` strips a 20-byte prefix/suffix and decompresses the middle before reading ID/star pairs | Public rated-level refresh from real stored levels; URL-safe Base64 of a zlib stream with 20-character padding at each end; exclude deleted, unlisted and friend-only levels |

Rating information authenticates the caller and uses the same friend-only
level access check as downloads/comments. It remains available in read-only
mode. No fabricated votes, player identifiers or moderator names are returned.
The popup reads its `Req` value from the downloaded level, not a third response
section. Custom quality suggestions are not supported by the existing rating
service; their count stays zero rather than deriving votes from level quality.

`tests/compat/shared-p0-http.py` checks real independent PHP workers and an
isolated MariaDB, through `public/php7.php`: histogram framing/latest-vote
semantics, private-level rejection, auth, malformed fields, read-only behavior,
queue filtering/counts, retained reports, player login, and audit/unmute.
The saved-level refresh is available without an account in read-only mode,
requires the common game secret and never returns private account saves.
The response layout was also checked against the historical response in
[gd-docs getSaveData](https://github.com/gd-docs/gd-docs/blob/main/docs/endpoints/misc/getSaveData.md):
after removing the first/last 20 characters, URL-safe Base64 and ordinary zlib
decompression produce 31,726 comma-separated fields (15,863 ID/star pairs).
That document marks the official endpoint as historical/nonfunctional since
2.2; a string and a callable function in this EXE do not establish UI use.

## Remaining embedded operations

The source inventory still distinguishes these from implemented routes:

* `submitGJUserInfo`: request `0x140260b20` submits `udid`, `accountID`,
  `levelsInfo` and the game secret. These fields alone do not authorize changes
  to trusted account progress. A genuine operation remains unimplemented.
* `uploadMPComment`: multiplayer comment request `0x140391240` uses `gameID`,
  `extra` and a checksum. It must not be aliased to level comments.
* `lostpassword` and `accountManagement`: browser-facing account pages. The
  shared `lostpassword` and `lostusername` paths now invoke the English
  RecoveryController, including CSRF protection and mount-aware assets.
  `accountManagement` still needs a dedicated self-service account page.
  Absence from Application's game route list does not make a shared recovery
  handler absent.

Existing provisional 2.209 serializers and native device acceptance still need
their own verification. Registering these four routes does not waive release
gates or establish 100% compatibility.
