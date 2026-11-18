# GD 2.209 social endpoint security and protocol batch

Date: 2026-11-18. Scope: PHP8 canonical runtime + compiled PHP7, not Go.

## Confirmed source-level defects addressed together

1. **`likeGJItem211` / item votes.** PHP8 `LikeController` converted a
   malformed declared `accountID` to zero and could fall back to an
   anonymous IP vote. Its PHP7 compiled copy had a more severe divergence:
   it accepted an anonymous downgrade when an authenticated request lacked
   valid credentials, could override comment `type` with a contextual
   `levelID`, ignored the repository's `false` return and replied `1`
   even when the vote failed.
   Both controllers now share guarded, PHP7-compatible request semantics:
   malformed explicit `accountID`, `itemID`, `type`, `like` are
   rejected; level fallback is only for level votes; explicit comment
   item type wins over any context `levelID`; authenticated requests never
   downgrade to anonymous; backend failure returns `-1`.
2. **`uploadGJComment21` / `getGJComments21` / delete comment routes.**
   Both PHP8 and compiled PHP7 still used
   `$request->postInt(...) ?: (int)($_POST[...] ?? ...)`. This reinterpreted
   malformed and overflowed IDs (e.g. `12junk`) as real IDs and made the
   route depend on ambient PHP global state instead of its Request object.
   All 15 such casts, plus an unsafe comment string fallback, were removed.
   `Request::fromGlobals()` already copies PHP's POST data for ordinary
   HTTP requests, including older versions.
3. **`downloadGJMessage20` / read state.** Reading a message selected the
   authenticated participant correctly, but then the update of `is_read`
   used only `WHERE id=:id`. It now also requires the authenticated
   recipient, non-deleted receiver copy, and unread state. Sender reads
   remain read-only.

4. **Friend requests, friend/block lists, 2.209 followed/muted users.**
   `RelationshipRepository` used `JOIN profiles` for six list queries.
   Imported accounts without a profile row were missing from results even
   when a matching friend/request/follow/block row existed. The request count
   could therefore disagree with its visible page. All six now use
   `LEFT JOIN profiles`, relying on existing encoder defaults for icons,
   username and fallback user ID. No new profile is created on a read.

## Regression coverage

- `tests/application/like-integrity-integration.php`: MariaDB vote
  integrity plus invalid account/item/type/polarity, no side-effect
  counters, no anonymous downgrade and contextual level safeguard.
- `tests/application/comment-contract.php`: strict GD Request numeric
  parsing and absence of unsafe POST fallback on all comment routes.
- `tests/application/gd2209-private-messages-integration.php`:
  sender read does not acknowledge a recipient message; recipient read still
  marks it read, and deleted/unrelated actors remain forbidden.
- `tests/compat/php7-social-input-parity.php`: cross-runtime guard for
  compiled PHP7 and canonical PHP8, also executed under PHP 7.0 CI.
- `tests/application/gd2209-legacy-social-profiles-sqlite.php`: real SQLite
  query regression for missing legacy profiles in inbox/outbox requests,
  friends, blocks, followed and muted lists. Validates wire fallback and
  request count consistency.
- `compat/php7/core/sources.json` pins the four changed PHP8 originals.

## Non-goals and acceptance

These are targeted server-side fixes, not a claim that the 166 known
endpoints have all been certified in actual Windows/Android GD 2.209. No
changes to Go, no deployment, no external code copied. A real client
acceptance run, hosting-specific integration, and abuse/load tests remain
necessary. PHP7.0 itself is end-of-life.
