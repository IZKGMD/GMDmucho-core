# GD 2.209 endpoint backend coverage — staged implementation

**As of October 18, 2026: 52/52 route names registered. NOT certified native-client compatibility.**

The 25 formerly missing names now route to real PHP services on this *feature branch*, rather than to unconditional success markers. This work is intended for staging and review, not production.

## Implemented backend effects

- reportGJContent: credential-backed level-only reports using the documented native `type=1` (type 0 denotes a user). Valid comma-separated reasons are stored in the existing report record; other content types currently reject.
- adminGetUserHistory, adminGetReportedContent, adminGetAbuseInfo, adminGetAccountReview: moderator-only reads of persisted account data, real reports and action history.
- adminClearReportedContent, adminCloseCase: transactionally close scoped pending reports and audit the mutation. Incorrect IDs, permissions or unknown cases fail.
- adminDeleteUserComments, deleteGJComments: permission-scoped, bounded, transactional deletion of real level comments and associated likes.
- adminThreadAction: limited to verified-message deletion by elder moderator/owner; unknown action strings fail closed.
- adminCommentBan/Unban, adminUploadBan/Unban, adminMessageBan/Unban: persistent, timed or indefinite restrictions, with enforcement in normal upload, comment, private-message and thread-message services.
- adminFreezeAccount/UnfreezeAccount: preserve original active state and restore it when unfreezing.
- adminTrendingBan/Unban: persistent restriction enforced in trending level searches.
- adminNameLock/Unlock: persistent restriction enforced on legacy username edits; other account editing surfaces require audit.
- getGJAudioFilesize: compute sizes only from verified song metadata and locally persisted editor audio; unknown records fail rather than inventing SFX sizes.
- getGJContentStatus: credential-backed status of persisted restrictions.
- openedRegister: documented account-secret-only POST generates a fresh 32-character hexadecimal token with only its hash stored; this is not yet wired into the subsequent registration token-consumption handshake.

Existing 27 routes include social following, notifications, threads, editor audio, telemetry, song usage, user search and moderation queue. Many existing implementations also have provisional wire formats.

## Security checks

- All privileged actions use the real account password-derived credential and database roles.
- Normal users and rating advisors cannot perform elder moderation actions.
- Accounts with equal or greater role privileges cannot be sanctioned by a lower/equal role.
- State changes are transactional and logged in a dedicated moderation case audit table.
- Credentials and raw device/session tokens are not stored in audit tables.
- Account sanctions are checked server-side independently of client-reported GD version.
- Three migrations are required: 20261018_001, 20261018_002, 20261018_003.

## Not yet verified

The real Geometry Dash 2.209 binary has NOT confirmed many response formats, parameter aliases, enum values or empty/error semantics. Only the report queue response header was directly recovered from the native parser. Other moderator read envelopes are provisional; file-size response framing, content status and openedRegister receipt framing are also provisional. Native `reportGJContent` type 1 is level; type 0 is user. Other report categories, anonymous reporting and the later registration token-consumption step require additional work.

**Do not claim 100% compatibility, do not deploy blindly and do not call passing route inventory a native-client release gate.**

## Validation before release

1. Apply migrations and test on isolated staging MariaDB, not a live player database.
2. Run php bin/mucho-gd2209-coverage.php --json and php tests/protocol/endpoint-perfection.php.
3. Run php tests/application/gd2209-case-actions-integration.php and php tests/application/gd2209-sanctions-utilities-integration.php on isolated MariaDB.
4. Run historical protocol, client and performance regressions.
5. Capture redacted native 2.209 Windows and Android request/response fixtures. Do not publish passwords, gjp2, session tokens, private content or device identifiers.
6. Recover each native response parser and input contract; certify individual endpoints based on real-client acceptance.

References: docs/GD_2209_NATIVE_EXE_FINDINGS.md, docs/GD_2209_COVERAGE.md and docs/GD_2209_VERIFICATION_GATES.md.

**Publication order:** Keep GD-2.209-Decompiled unchanged until the backend branch and native protocol findings pass the appropriate gates.

## Newly grounded documentation (October 18)

- GD Docs: https://boomlings.dev/endpoints/accounts/openedRegister — requires account `secret` and returns a 32-character hexadecimal session token; the previous diagnostic-only `1` response was incompatible and has been corrected in this branch.
- GD Docs: https://boomlings.dev/endpoints/misc/reportGJContent — `type=0` is user, `type=1` is level, `reasons` can be comma-separated. The mistaken level type 0 mapping was corrected; anonymous reports and other content families still need safe implementations.
- GD Docs: https://boomlings.dev/endpoints/threads/getGJThreadMessages — native success framing has seven `#`-delimited segments; the current MuchoCore thread controller's simple message list is **not yet native-compatible**. Do not mark this endpoint verified before fixing the serializer and testing real client acceptance.

## GD 2.209 thread wire follow-up

The staged thread controller now emits the documented five-value message object and seven-part message response, uses Base64 query/body values and native numeric update modes, and returns actual persisted message IDs. Group thread listings use positional object records and real pagination/count information. Supporting isolated tests were added for serialized framing, persisted message IDs, and outsider authorization. See [GD_2209_THREADS_NATIVE_WIRE.md](GD_2209_THREADS_NATIVE_WIRE.md).

**Still unverified:** full group metadata, native invite objects, departed members, DMs, threadID=0 creation, several update modes, full native game acceptance. This remains an unfinished staged implementation, not 2.209 parity certification.
