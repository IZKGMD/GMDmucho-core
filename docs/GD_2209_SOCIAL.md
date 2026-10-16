# GD 2.209 server compatibility: staged rollout

This document distinguishes **implemented backend operations** from **verified
native-client compatibility**. New endpoint strings extracted from GD 2.209
do not specify request field names, response framing, error semantics, or
whether a feature can be used without additional client resources.

## Phase A — persistent following and notifications

These routes are now registered through `public/database/*.php` →
`src/Core/Application.php` → the dedicated 2.209 controllers:

| Route | Actual server behavior | Status |
|---|---|---|
| `getGJFollowing.php` | Authenticated, paginated following/follower list | Provisional wire |
| `syncGJFollowing.php` | Authenticated read-only server-side following snapshot | Provisional semantics |
| `followGJUser.php` | Authenticated, blocked-user-aware idempotent follow | Server-side |
| `unfollowGJUser.php` | Authenticated idempotent unfollow | Server-side |
| `muteGJUser.php` | Authenticated per-account mute/unmute | Server-side |
| `getGJNotifications.php` | Authenticated private notification inbox | Provisional wire |
| `claimGJNotificationReward.php` | Authenticated, single-use mark-as-claimed on owned notification | **No currency reward yet** |

The account ID by itself provides no authority. All operations require a
valid GD credential. Follow/mute disallow self-targeting and targets blocked
in either direction. Followers cannot change another account's data. The
follow + notification creation is atomic. Migration-created tables use unique
keys, index-backed pagination and cascading foreign keys.

The `following` response reuses MuchoCore's existing 2.2 user encoder as a
temporary compatibility boundary; **that is not proof** that GD 2.209 uses
the same response layout. Notification serialization is similarly provisional.
`syncGJFollowing.php` deliberately does not overwrite the database with
unauthenticated/unvalidated target IDs. A claimed notification records state
only; diamonds, orbs and other rewards must not be invented before an
official request/response capture confirms the rules.

### Real-client verification still required

Capture **redacted** 2.209 traffic (POST parameter names + response
structure; never share `gjp`, `gjp2`, cookies, passwords, tokens or private
messages) for each route. Add it to `tests/client-fixtures/2.209`, update
serializers and mark corresponding endpoint contracts as covered only when
runtime tests succeed.

### Subsequent native API work (not complete)

- Group/thread messages:
  `getGJThreads`, `getGJThreadMessages`, `getGJThreadMembers`,
  `getGJThreadInvites`, `sendGJThreadMessage`,
  `searchGJThreadMessages`, `updateGJThread`.
- Editor sounds:
  `getGJEditorSounds`, `uploadGJEditorSound`,
  `downloadGJEditorSound`, `deleteGJEditorSound`, `updateGJSoundDesc`.
- Death positions:
  `getGJDeathData`, `uploadGJDeathData`.
- Custom level controls:
  `getGJLevelControls`, `uploadGJLevelControls`,
  `deleteGJLevelControls`.
- Level rate/info, song usage, content status, user search and reporting:
  `getGJContentStatus`, `getLevelRateInfo`, `getGJSongUsage`,
  `getGJUserSearch`, `reportGJContent`.

These other endpoints deliberately continue to fail closed rather than
fabricating empty or success responses for unimplemented game functionality.

## Test commands

```sh
php tests/protocol/endpoint-perfection.php
php tests/application/gd2209-social-integration.php
```

The MariaDB test needs an isolated local test instance and explicit
`TEST_DB_HOST=127.0.0.1`, `TEST_DB_ROOT_PASSWORD` credentials. It cannot
run against production.
