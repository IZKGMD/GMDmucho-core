# GD 2.209: private-message upload diagnostics (PHP 8)

Issue: [#308](https://github.com/IZKGMD/GMDmucho-core/issues/308). This is a **staging-only troubleshooting tool**, not proof that the reported native-client failure is fixed.

## Scope and privacy

On an **isolated PHP 8 test tenant only**, set `MUCHO_MESSAGE_DIAGNOSTICS=1` in the tenant configuration and reload its PHP workers. Leave it disabled for ordinary installations. The trace logs only a random, server-generated request ID plus an allowlisted phase and reason. It never logs usernames, account IDs, IP addresses, GJP/GJP2, message subject/body, raw POST parameters, or exception messages.

The game response is unchanged: `1` means accepted by the application, `-1` means rejected. MuchoProtect limits are unchanged and must not be disabled to make a native-client test pass.

Trace example (request IDs are random per incoming request):

```text
[MuchoCore MessageTrace] request_id=0123456789abcdef phase=entry reason=seen
[MuchoCore MessageTrace] request_id=0123456789abcdef phase=protection reason=allowed
[MuchoCore MessageTrace] request_id=0123456789abcdef phase=controller reason=called
[MuchoCore MessageTrace] request_id=0123456789abcdef phase=storage reason=stored
[MuchoCore MessageTrace] request_id=0123456789abcdef phase=controller reason=allowed
```

## Interpreting traces

| Last record or reason | Meaning / next investigation |
|---|---|
| No `entry` record | The upload may not have reached this PHP 8 entrypoint. Verify the patched client endpoint, HTTP routing, and test-tenant destination. Check whether the diagnostic flag actually loaded. |
| `protection:blocked` | MuchoProtect denied the request before the handler. Inspect its separate security audit (metadata only); do **not** bypass the limits. |
| `authentication:rejected` | Invalid or missing credential. Confirm the test account and GJP2-generation path; do not log credentials. |
| `decoding:invalid_subject` / `invalid_body` | Client wire payload failed strict Base64 / XOR / UTF-8 validation. Capture only the non-secret failure category and a test fixture. |
| `policy:message_banned`, `invalid_target`, `recipient_policy` | Sender restriction, invalid recipient or recipient privacy / blocking / inactive status. Reproduce with two disposable accounts and open message permissions. |
| `policy:empty_subject`, `empty_body`, `subject_too_long`, `body_too_long` | Application input validation refused the payload; adjust the **disposable** fixture, not the real message. |
| `storage:insert_rejected` or `controller:exception` | Investigate schema/migrations, database availability and sanitized exception class through server-side tools. Never put SQL errors or message contents in public GitHub comments. |
| `storage:stored` and `controller:allowed` | The application acknowledged a database write. Verify exact row persistence on testgdps and recipient inbox/read behavior before calling the full workflow PASS. |

Use a **real patched GD 2.209 (gameVersion 22, binaryVersion 51)** with two disposable accounts on testgdps. Correlate only request IDs and non-secret outcome codes. Do not commit raw network captures or log files. Disable the feature and reload workers after testing.

## Safety and validation

- `php tests/protocol/gd2209-message-trace.php`: verifies allowlisted diagnostic output and checks that untrusted text / malformed IDs never enter logs.
- `php tests/application/gd2209-private-messages-integration.php`: verifies persistence and inbox access on isolated CI MariaDB.
- `PHP 8 HTTP acceptance` and `Validate` workflows verify the PHP 8 integrated application.

Without sanitized native-client evidence, issue #308 remains open and PHP 8 Stable release gate #142 is not certified.
