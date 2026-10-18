# GD 2.209 thread protocol — staging implementation

This is a reference for the current PR, **not a claim of complete native parity**.

## Grounded wire contracts

Native source: https://boomlings.dev/endpoints/threads/getGJThreadMessages

- `getGJThreadMessages` response has seven `#`-delimited segments: messages, members, former members, invites, metadata, lastVisibleMessageID, fixed tail `3,1,10,50`.
- Normal message object has five *positional* fields: message ID, sender account ID, Base64 body, UNIX timestamp, deleted flag.
- Empty message section is the literal `none`.
- Request cursors `beforeID`, `afterID` and `aroundID` are implemented; `aroundID` uses a bounded 25-older/25-newer window. Their combined or ambiguous use is rejected.
- Access to the message snapshot is limited to active thread members. The member-roster endpoint also permits invited accounts.
- `sendGJThreadMessage` accepts URL-safe Base64 in `body` and replies `threadID:messageID` for an existing thread. Creating a new DM/group with `threadID=0` is implemented with atomic thread/message creation and optional group invitations.
- `getGJThreads` uses fixed positional thread objects with pagination metadata `total:offset:10:invites:60`, not keyed `1:value:2:value` objects. The staging schema now stores both group and DM threads.
- `searchGJThreadMessages` accepts Base64 query and `beforeID`, returning `messages#remainingPages`. The remaining pages are counted from actual database matches.
- `updateGJThread` accepts native numeric `mode` and Base64 `value`, with documented modes 1–16 (except mode 17, which is undocumented) and 18, using owner/member permissions; unsupported modes fail closed.

Sources:
- https://boomlings.dev/endpoints/threads/getGJThreadMessages
- https://boomlings.dev/resources/server/threadmessage
- https://boomlings.dev/resources/server/thread
- https://boomlings.dev/endpoints/threads/getGJThreads
- https://boomlings.dev/endpoints/threads/getGJThreadMembers
- https://boomlings.dev/endpoints/threads/searchGJThreadMessages
- https://boomlings.dev/endpoints/threads/sendGJThreadMessage
- https://boomlings.dev/endpoints/threads/updateGJThread

## Remaining compatibility limitations

**Critical:** Exact native values for several optional invite/member fields and UI counters remain provisional. Group description/emote, member mute/pin/read cursors, former-member records and DM messages are now stored, but exact real-client rendering and departed-member serialization require further analysis.

Incoming invite responses now use the documented nineteen-value positional envelope, but several optional attributes remain provisional and real-client parsing is not yet verified. The content and structure of optional system messages are also not implemented.

No real-client Windows or Android packet capture validating these new commits is present. Code only on draft PR #133. Do not merge to `main` or deploy to production solely on the strength of route and unit-test coverage.

## Regression checks

- `php tests/protocol/gd2209-thread-wire.php` validates pure string serialization (five-field message, seven-section response).
- `php tests/application/gd2209-thread-integration.php` on isolated MariaDB validates database persistence, sender ID, membership isolation, Base64 input, native list/header and numeric update modes.
- Existing auth/restriction tests must remain green. Current commits have not been certified by an actual game client.


## Native new chat creation and management (staged)

- New threads are created with `sendGJThreadMessage` using `threadID=0`; `isDM=1` and `toAccountID` start/reuse a canonical DM pair. Groups decode `subject`, `description`, `emote` and comma-separated `targets` from URL-safe Base64.
- The first message, member records and invitations are persisted in a single transaction; the response contains actual thread and message IDs, plus the documented DM or group suffix.
- Group invitees cannot read messages before acceptance; DM recipients are actual members. Permission checks include account activation, message privacy, blocks and message-send restrictions.
- Native update modes now implement personal clear (1), rename (2), mark read (3), invite multiple users (4), leave (5), kick (6), transfer ownership (7), accept/ignore invite (8/9), mute (10), revoke invite (11), delete own message (12), invite permission (13), description (14), pin (15), emote (16) and dismiss kicked thread (18).
- Kicks and voluntary departures are tracked in a former-members table for future UI support; mode 1 never destroys other participants' messages.
- New migration: `database/migrations/20261018_004_gd2209_native_threads.php`. Existing installations must migrate before using these handlers. Native list entries now distinguish DM/group type and include actual peer identity.
- Inbound `getGJThreadInvites` now emits fixed nineteen-field records based on persisted inviter/thread metadata; its optional positional fields remain provisional.
- Native `aroundID` message fetch uses a bounded window and enforces membership, clear-history cutoffs, and cursor exclusivity.

## Verification caveats

Integration coverage is checked into `tests/application/gd2209-thread-integration.php`; it runs against isolated MariaDB in CI. This implementation has **not** been accepted by a real unmodified Windows or Android GD 2.209 client. Exact invite object defaults, DM metadata, group read receipts, real-client rate behavior and former-member framing require network fixture verification.

All changes remain on a draft pull request. Do not claim fully verified protocol compatibility solely from registered routes or PHP tests.
