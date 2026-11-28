# GD 2.209 notifications and thread invitations — batch audit

Date: 2026-11-18. Scope: PHP8 canonical and compiled native PHP7.
No Go or 5v.pl/VPS deployment.

## Verified source defects and corrective changes

1. **Native PHP7 notification format divergence.** Canonical PHP8 already
   encoded the supported `follow` class as 16 positional key/value pairs,
   with URL-safe Base64 title/body and the documented type 13. Native PHP7
   still returned an unrelated 9-field legacy format and included every
   database notification type in its count/pagination. The native PHP7
   `NotificationService` is now ported to the same restricted follow-only
   wire contract, with PHP7.0-compatible syntax. Unsupported event classes
   are not guessed or serialized.
2. **Unsupported notification claims.** Both PHP runtimes previously
   allowed `claim` to set `reward_claimed=1` on unknown future/imported
   notification types even though the server cannot serialize or award
   their unverified semantics. Claim writes now require
   `notification_type='follow'` and the authenticated recipient.
3. **Overflowed IDs accepted by parser.** Both
   `Gd2209ThreadActionService::positiveIds` and
   `Gd2209NativeThreadOperations::targetIds` used `intval()` after
   validating decimal syntax. A positive decimal larger than PHP_INT_MAX
   could silently become PHP_INT_MAX, potentially aliasing a real ID.
   Both original and compiled handlers now reject overflow before opening
   a transaction.
4. **Idempotent invitation rejected at 32-person limit.** Native mode 4
   counted every supplied invitee as one *new* slot even if the person
   already had a pending invitation. At full capacity, re-inviting that
   existing recipient therefore incorrectly returned failure. The
   existing thread-row transaction lock already serializes native modes;
   capacity checks now count only novel pending invitees. A genuinely new
   invite is still refused at 32 member+pending slots. Existing member
   checks and mutual blocks remain enforced.

## Regression checks

- `tests/application/gd2209-thread-integration.php`: real MariaDB
  capacity at 32 including 29 fixture invitees, pending replay,
  rejection of the 33rd member and overflowed ID.
- `tests/application/gd2209-social-integration.php`: unsupported
  notification events remain outside the native list and cannot be
  claimed, without changing the genuine follow payload.
- `tests/compat/php7-native-notifications-mysql.php`: isolated MariaDB
  test run by both native PHP7.0 and PHP8.3, checking the actual compiled
  PHP7 16-slot wire fields, owner permissions, duplicate claims, unknown
  event types, Base64 and pagination.
- Native PHP7 source files mirrored; source fingerprint manifest
  `compat/php7/core/sources.json` updated.

## Boundaries

These fixes do not introduce an arbitrary reward/currency, turn unverified
notification types into the documented follower class, or claim full
native 2.209 client parity. The official client wire still needs redacted
real-world captures for unmapped notification types and thread metadata.
PHP 7.0 is end of life and not recommended for new production hosting.
