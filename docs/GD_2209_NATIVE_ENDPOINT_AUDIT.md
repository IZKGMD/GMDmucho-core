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
| `accountManagement` | Browser-facing account URL embedded in the EXE | English player password-change form, current-password verification, session CSRF and strict rate limits; atomically replace password/GJP2 hashes, revoke recovery links and legacy upload sessions, and audit the change |

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

The source inventory still distinguishes these from operations certified by a real client:

* `submitGJUserInfo`: request `0x140260b20` carries `udid`, `accountID`,
  `levelsInfo` and the public game `secret`; the account credential is checked
  through GJP/GJP2. PHP 8 stores the opaque payload in the private
  `mucho_account_user_info` table (8 MiB limit), with per-IP and
  account+credential throttling. It never treats this client-supplied data as
  trusted progress or changes profile statistics.
  UDID-only/unregistered submissions remain disabled. This follows the
  authenticated opaque-storage behavior in
  [MegaSa1nt's reference implementation](https://github.com/MegaSa1nt/GMDprivateServer/blob/master/incl/misc/submitGJUserInfo.php).
  Native-client acceptance still requires a real 2.209 request/response test.
* `uploadMPComment`: multiplayer lobby comment request `0x140391240` uses
  `accountID`, `gjp2`, `gameID`, URL-safe Base64 `comment`, `extra`,
  `secret` and `chk`. A third-party client helper
  [GDServerTools/GDReq.py](https://github.com/ryder7223/GDServerTools/blob/main/GDReq.py)
  suggests `chk = Base64Url(XOR(SHA1(accountID + rawComment + gameID + extra +
  "xI25fpAapCQg"), "52832"))`, but this is only a candidate formula, not a
  verified server contract. The [published endpoint notes](https://boomlings.dev/endpoints/multiplayer/uploadMPComment)
  explicitly warn that their description is based on leaks, unused code and
  guesses; the response remains unknown. No server implementation was found
  in the two primary reference kernels. The current client patch redirects
  this URL, but the corresponding lobby join/read flow has not been verified.
  Keep it unimplemented until a real GD 2.209 request/response trace confirms
  the checksum, response and visibility semantics; do not alias it to level comments.
* `lostpassword` and `accountManagement`: browser-facing account pages. The
  shared `lostpassword` and `lostusername` paths now invoke the English
  RecoveryController, including CSRF protection and mount-aware assets.
  `accountManagement` now provides a dedicated self-service password page;
  username/email changes and account deletion are not exposed by that form.
  Absence from Application's game route list does not make a shared recovery
  handler absent.

Existing provisional 2.209 serializers and native device acceptance still need
their own verification. Registering these four routes does not waive release
gates or establish 100% compatibility.
