# GD 2.209 Cloud Save — PHP8/PHP7 parity and resilience audit

2026-11-18. Applies to the native Geometry Dash account backup and sync
endpoints backed by MuchoCore's original Cloud Save service and repositories.
No Go code changed.

## Source-confirmed defects

1. **First-save concurrency (native PHP7 only).** The canonical PHP8
   repository first locks the existing account row with
   \`SELECT account_id FROM accounts WHERE account_id=:account FOR UPDATE\`
   before reading any optional save row. The compiled PHP7 repository
   had only \`SELECT ... FROM mucho_cloud_saves ... FOR UPDATE\`.
   When no child row exists, concurrent first backups can race when
   choosing revision 1. PHP7 now takes the same account row lock inside
   its transaction, and rejects nonexistent account rows.
2. **Legacy history pruning (native PHP7 only).** PHP7 previously fetched
   at most 100 history row IDs and issued a DELETE for each row beyond
   the newest five. An imported history larger than 100 revisions would
   remain partly unpruned after a save. The compiled repository now reads
   only the five highest revision numbers and performs one indexed DELETE
   for the entire older tail, matching canonical PHP8's exact retention
   limit without N+1 deletes.
3. **Ambient global POST fallback (PHP8 and native PHP7).** Both
   \`CloudSaveController\` implementations used \`$_POST\` when
   \`$request->post\` was empty. This undermined the request abstraction
   and could make an explicitly empty request inherit unrelated ambient
   credentials or save data. Controllers now always use the already
   parsed Request fields; legitimate HTTP requests constructed through
   \`Request::fromGlobals()\` are unaffected.
4. **Log account ID coercion (PHP8 and native PHP7).** Exception logging
   cast arbitrary \`accountID\` data to an integer; arrays or malformed
   values could produce misleading account IDs. Logging now uses the
   same strict Request integer parser as the protocol layer.

## Acceptance tests

\`tests/compat/php7-native-cloudsave-history-mysql.php\` runs on isolated
127.0.0.1 MariaDB under both PHP 7.0 and PHP 8.3 via native CI, and
on PHP8 in Validate. It uses a disposable DB and a fresh private random
key, with cleanup, and proves:

- AES-GCM binary payload round-trip and duplicate idempotence;
- encrypted envelope corruption cannot be acknowledged as a duplicate;
- missing accounts cannot create orphan saves;
- imported history with more than 100 prior entries reduces to exactly
  five latest revisions in **one** subsequent backup;
- further identical backups do not alter the revision history;
- separate account history stays isolated;
- actual password-backed backup and GJP2-authenticated 2.209 sync;
- an explicitly empty Request cannot consume an unrelated global POST;
- a rotated key rejects existing encrypted data, and the original key
  restores it without data loss.

The existing canonical PHP8 test
\`tests/application/cloudsave-consistency-integration.php\` remains in
Validate and covers baseline behavior independently.

## Limits and release gates

This patch does not rotate Cloud Save keys, modify data formats, import
other kernels, or deploy to 5v.pl/VPS. Schema migrations remain intact.
It is a server-side correctness and database integration fix, **not** proof
of functional certification from the real Windows/Android 2.209 client.
A live-client backup/sync/download test with a disposable account on the
actual hosting provider remains necessary, as does real concurrency/load
acceptance under provider resource caps.

PHP 7.0 is end of upstream security support.
