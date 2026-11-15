# PHP 8 security review — 2026-11-15

Review baseline: `b11cdd607b6287e1e288f5b70b69cd5cee0988b1`.
This is a scoped source review and regression pass, not an exhaustive security certification.

## Findings addressed

| Priority | Finding | Correction and regression |
| --- | --- | --- |
| P1 | Player dashboard sessions contained only account ID/name. Password changes and recovery did not revoke existing browser access. Dashboard music upload also did not refresh banned/inactive state. | Bind sessions to a one-way fingerprint of both current credential hashes; resolve current account eligibility on dashboard and clan requests. Reject old unbound sessions. Test the actual page guards with SQLite and real login cookies/password changes/bans through PHP-FPM HTTP acceptance. |
| P1 | Public comment history filtered `is_unlisted` but omitted the independent friends-only flag `unlisted2`. A friends-only level with `is_unlisted=0` could expose comment text and its ID in public history. | Filter both flags for rows and counts. Extend the real MariaDB history fixture with a high-ranked private comment; existing identity, pagination and wire assertions must still exclude it. |
| P1 | Friends-level search reused `:id` twice in a UNION with native PDO prepares. The resulting SQL failure was swallowed and treated as no friends, hiding all friends' levels. | Bind unique outgoing/incoming placeholders. Exercise both friendship directions and an unrelated account in the existing native-PDO MariaDB level fixture. |

Existing browser logins must sign in again once after deployment. Account data,
passwords, Cloud Save keys and schema are not changed by these fixes.
Compatibility copies carry only the corresponding lowered changes and source
provenance entries; existing shared-host page adapters remain intact.

## Areas examined

- Account authentication, password changes/recovery and legacy 1.9 session boundaries.
- Dashboard/clan browser identity and upload authorization.
- Private-message ownership/deletion and friend-request read state.
- Level upload/update/delete ownership, private downloads, comment history and lists.
- API v2 protection and music upload/verified-song visibility.
- Migration serialization, backup file protection, cache invalidation and revision writes.
- Administrator API permission refresh and password-recovery token revocation.
- AST scan of 238 PHP files and 617 literal prepared/query statements for repeated named placeholders; the friends-search query was the only candidate in this scope. Dynamic SQL is not covered by this scan.

This pass does not prove all concurrency, deployment or native-client behavior.
Use the existing Validate/MariaDB/HTTP suites alongside the new regressions.
In particular, native GD 2.209 message loss (#308), native-client certification
(#142), real SMTP delivery and the remaining PHP 8 Stable gates (#245) remain open.
Do not close those issues based on synthetic HTTP tests.
