# Native Geometry Dash 2.209 Windows executable: protocol observations

*Research date:* 2026-10-17  
*Filename supplied by project maintainer:* `GeometryDash(2).exe`  
*SHA-256:* `524265b3781596fcc7c9e4d60dc5ac2d0a4cd9530ee3ea637e123f9ac1f9b6a8`  
*Format:* Windows PE32+, x86-64; .text/.rdata/.data/.pdata/.rsrc/.reloc  
*Provenance:* verified locally from fresh conversation upload; binary is **not**
copied to this repository as part of these changes.

Static inspection with `strings -a`, `objdump` and a byte-scoped .rdata
search confirmed the exact literal endpoint names/request format templates below.
The excerpts are constant format strings embedded in the client, **not**
private HTTP traffic and not captured server responses.

## Newly verified exact native templates

| Endpoint | Embedded request format fragment |
| --- | --- |
| `adminGetReportQueue.php` | `&page=%i&sort=%i&mode=%i&flags=1&secret=%s` |
| `adminGetReportedContent.php` | `&targetAccountID=%i&page=%i&sort=%i&ctype=%i&secret=%s` |
| `adminGetUserHistory.php` | `&targetAccountID=%i&page=%i&secret=%s` |
| `adminGetAbuseInfo.php` | `&targetAccountID=%i&page=%i&history=%i&secret=%s` |
| `adminGetAccountReview.php` | `&targetAccountID=%i&secret=%s` |
| `adminClearReportedContent.php` | `&targetAccountID=%i&items=%s&secret=%s` |
| `adminThreadAction.php` | `&threadID=%i&action=%s&messageID=%lli&secret=%s` |
| `adminCommentBan.php` | `&mode=%s&durationMinutes=%i` |
| `adminCloseCase.php` | `&outcome=%i` |
| `syncGJFollowing.php` | `&follows=%s&ack=1&secret=%s` |
| `getGJFollowing.php` | `&ack=1&secret=%s` |
| `getGJUserSearch.php` | `&secret=%s&str=` |
| `getGJSongUsage.php` | `&songID=%i&secret=%s` |
| `getGJContentStatus.php` | `&secret=%s&special=1` |
| `getGJAudioFilesize.php` | `&secret=%s&songIDs=%s&sfxIDs=%s` (near the endpoint) |
| `reportGJContent.php` | `type=%i&targetID=%i&special=%i&reasons=%s&accountID=%i&gjp2=%s&secret=%s` |

**Caveat:** these are **fragments**, not a full HTTP form. GameLevelManager
appends common account, version, device, transport, auth and security fields
elsewhere. Not all fragments shown are adjacent to their endpoint URL;
the audio-size fragment occurs shortly before that URL. Do not conclude an
absent fragment means a request field is absent.

## Moderation Queue is account-based

Native UI help text states (paraphrased):

- A reported account gains **three abuse points** per player report.
- Text-filter hits add **one point** each from the last seven days.
- Once a threshold is met, an **account case** opens and enters the queue.
- Moderator tabs are **Reports**, **Flagged**, and account-centric **Review**.
- Case resolution can clear reports, close the case and log the outcome;
  bans and content deletion **do not automatically close** it.

The request tag is `adminReportQueue`, and the EXE has
`AdminReportQueueEntry` RTTI. This decisively distinguishes the 2.209
moderation queue from the earlier concept of a list of reported levels.
Accordingly, queue storage groups by **reported account**, not `level_id`.
Where the existing MuchoCore backend lacks text-filter abuse events or a
native threshold setting, **do not fabricate** their existence.

## Verified vs not verified

- **Verified:** EXE hash, native endpoint names, exact literal request
  format fragments, the queue's account-based abuse score concept, and the
  provided real client trace's `-1` failure.
- **Unverified:** RobTop's queue success/empty response shape, integer enum
  mapping for `reportGJContent.type`, meaning of `sort`/`mode`/`ctype`,
  threshold for case opening, authorization nuances of other admin actions,
  any undocumented keys or error payload framing.
- Until the native parser is reversed, moderation success-wire in
  `ReportQueueService` is **only a provisional adapter**, not certified.

## Static reproduction

For a locally obtained copy of the executable:

```sh
sha256sum GeometryDash.exe
strings -a -n 6 GeometryDash.exe | grep -A 2 'adminGetReportQueue.php'
strings -a -n 6 GeometryDash.exe | grep -A 3 'reportGJContent.php'
```

Never execute unknown downloaded binaries on the production VPS; static
inspection should be performed in an isolated development environment.

## Decompilation-level finding: native queue reply grammar

Located the `AdminReportQueueEntry` constructor and response parser by resolving
MSVC RTTI `.?AVAdminReportQueueEntry@@` to its virtual function table
and disassembling the related GameLevelManager routines.

- At image VA `0x140242a29`, the response parser searches for the literal
  `#` delimiter. **No `#` invokes its failed-load callback.**
- The part **before `#`** is split on `:` into three integer fields:
  `total:offset:pageSize`.
- The part **after `#`** is split into entries on `|`. Each entry splits
  on `:` and needs **at least 9 positional fields**.
- Each entry's first field is an integer account ID and the second a username;
  after parsing, the client uses both to create/lookup its user object.
- Optional extended record sections are parsed only when enough fields exist
  (12+, 18+, 20+). Their exact semantics are still being investigated.

**Consequence:** empty queue wire should be `0:0:20#`, not `-2`. A
populated page starts `TOTAL:OFFSET:20#ACCOUNT_ID:USERNAME:...`, not the
earlier incorrect `KEY:VALUE...#PAGINATION`. Old provisional replies would
have produced `Failed to load the queue` despite a registered HTTP route.
Our updated adapter follows the **verified framing and positional structure**,
but fields 2..8 remain provisional until their UI mappings are fully
recovered.
