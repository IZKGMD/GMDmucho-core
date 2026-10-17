# Geometry Dash 2.209 release gates (2026-10-17)

**Status:** work-in-progress. Neither route registration nor passing PHP tests proves
that the unmodified 2.209 game accepts every server response.

## Verified automatically on isolated MariaDB / PHP CI

| Area | What is covered | Limit |
|---|---|---|
| Level searches | Lightweight list SQL excludes LONGTEXT editor data; count query drops irrelevant joins; pagination, sorting and GD 2.2 list wire test | VPS latency reduction is not benchmarked |
| Level list moderator types 8/27 | Credential-gated visibility and suggestion ownership | Client native 2.209 UI still to be tested |
| GD 2.209 comment timestamps | Level and account comments emit Unix timestamp key 15 only with gameVersion>=22 and binaryVersion>=51; 2.2081 unchanged | Not yet checked with unmodified 2.209 client |
| GD 2.209 level dates | Level list and download keys 62/63 gated on binaryVersion>=51 | Song/SFX aggregate-size key 54 is pending |
| GD 2.209 profiles | Keys 66 (self unread), 67 (followers), 68 (self following count), 69 (viewer follows) from persisted DB; credentials required for viewer identity; legacy 2.2081 response unchanged | Invite privacy key 62, friendship timestamp 64 and unknown keys pending |
| GD 2.209 social lists | List types 3/4 plus player stats for binaryVersion>=51; legacy format unchanged | Full native client format to verify |
| Song usage | Documented tier/difficulty aggregation with DB-backed test | Native 2.209 acceptance pending |
| New admin report queue | New route, separate table, authenticated owner/elder-moderator access and safe reporting aggregation | Response framing is **provisional**; exact 2.209 mode/flags and report content support not yet known |
| Diagnostics | Privately classified response status, route request key names and numeric selectors only; no credentials/payloads logged | Must be deliberately enabled, disabled after use |

## Live request evidence

The unmodified 2.209 client reported `gameVersion=22` and
`binaryVersion=51` on `/database/adminGetReportQueue.php`. The old
deployed server returned literal `-1`, causing **Failed to load the queue**.
Request key names are known, **no successful native response has been captured**.
`getGJFollowing.php` independently returned `-2` (no results).

## Remaining to call it 100%

- Real native moderator queue request/response fixture, including accepted
  empty and nonempty lists, filter semantics, abuse review and related actions.
- `reportGJContent` across supported content families and the remaining new
  admin endpoints; do not synthesize successful mutations or bypass RBAC.
- Other undocumented endpoint request formats (for example
  `openedRegister`/`rsession`, `getGJContentStatus` and editor audio totals).
- 2.209 user fields 62/64, song+SFX total-size level field 54, and the
  downloadGJLevel death-data suffix.
- Real executable integration with Windows and Android plus game UI smoke tests,
  including registration, notifications, threads, uploads and moderation.

Compare `php bin/mucho-gd2209-coverage.php --json` to the
52-entry GD Docs 2.209 inventory. A missing route or a provisional wire means
the gate is still open. The goal is correctness, not a superficial 52/52 score.

## Safe rollout

Keep this change in the draft PR until it has a staged real-client run.
For testing on staging, enable the sanitizer with `MUCHO_CLIENT_TRACE=1`
in app environment, reproduce with an authorized tester, then inspect
`php bin/mucho-gd2209-trace-report.php --min-binary=51 --json` inside the app
container. Disable tracing afterward to avoid accumulating diagnostic logs.

Do not publish any intercepted passwords, `gjp2` tokens, UDIDs, IP addresses,
personal reports or full private responses. To reverse-engineer the remaining
client parsing logic, provide a legally obtained 2.209 EXE as a fresh
conversation upload; an unavailable binary reference is not sufficient.
