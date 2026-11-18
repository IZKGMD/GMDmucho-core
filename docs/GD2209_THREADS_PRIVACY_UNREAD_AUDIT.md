# GD 2.209 thread read privacy, native unread counters and legacy profiles

Date: 2026-11-18. Scope: canonical PHP8, compiled native PHP7.

## Findings: batch of four correctness defects

1. **Six inner joins dropped participants and invites.** Thread members,
   group invitation previews and invitation inbox selected `JOIN profiles`
   even when an imported account had no profile row. The resulting
   membership and invitation lists were incomplete. All six selects
   now use `LEFT JOIN profiles`. Missing cosmetic details use existing
   encoder defaults; `Gd2209ThreadWire::invites()` now falls back to
   the invitee's account ID for its numeric user-ID slot.
2. **Unread counts included the account's own sent messages.** The native
   list counted every message newer than `last_read_message_id`, including
   the current sender's posts and messages already hidden by
   `last_cleared_message_id`. The counter now requires a different
   sender and an ID above both the read and clear markers.
3. **Legacy readers bypassed clear-history.** After native
   `updateGJThread` mode 1, `ThreadService::messages()` and
   `ThreadService::search()` still returned old messages to the same
   user. These legacy adapters now apply the actor's own persisted
   `last_cleared_message_id`, without deleting or hiding messages
   from other thread participants. Native cursor queries already did.
4. **Sending trimmed user text only in existing threads.** Initial native
   messages preserved the decoded UTF-8 bytes, but subsequent posts
   called `trim($body)` before storing them. The service now validates
   with `trim($body) === ''` while saving the original bytes, so
   leading/trailing spaces survive a round trip.

## Evidence and regression gates

- Extended `tests/application/gd2209-thread-integration.php`
  with MariaDB checks for DM unread counts, clear/read interactions,
  legacy read/search restrictions, whitespace byte preservation,
  missing-profile member/invite visibility and wire fallbacks.
- Added `tests/compat/php7-native-threads-privacy-mysql.php`, a
  PHP7-syntax isolated MariaDB test for the compiled native runtime,
  running under PHP 7.0 and PHP 8.3 in native CI and PHP 8 in Validate.
- Synced the two PHP8 source fingerprints in
  `compat/php7/core/sources.json` while updating the native PHP7
  source copies independently.
- No migrations, no Go changes, no live hosting changes.

These tests demonstrate server-side behavior; real Geometry Dash 2.209
network traces remain necessary for full wire certification, especially
undocumented thread metadata and additional action modes. PHP 7.0 is
out of upstream security support.
