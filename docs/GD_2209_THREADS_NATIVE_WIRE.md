# GD 2.209 thread protocol — staging implementation

This is a reference for the current PR, **not a claim of complete native parity**.

## Grounded wire contracts

Native source: https://boomlings.dev/endpoints/threads/getGJThreadMessages

- `getGJThreadMessages` response has seven `#`-delimited segments: messages, members, former members, invites, metadata, lastVisibleMessageID, fixed tail `3,1,10,50`.
- Normal message object has five *positional* fields: message ID, sender account ID, Base64 body, UNIX timestamp, deleted flag.
- Empty message section is the literal `none`.
- Request cursors `beforeID` and `afterID` are supported. `aroundID` is deliberately rejected until window semantics are confirmed.
- Access to the message snapshot is limited to active thread members. The member-roster endpoint also permits invited accounts.
- `sendGJThreadMessage` accepts URL-safe Base64 in `body` and replies `threadID:messageID` for an existing thread. Creating a new DM/group with `threadID=0` is **not implemented** in this branch.
- `getGJThreads` uses fixed positional thread objects with pagination metadata `total:offset:10:invites:60`, not keyed `1:value:2:value` objects. Current DB stores only group threads, no DM types.
- `searchGJThreadMessages` accepts Base64 query and `beforeID`, returning `messages#remainingPages`. The remaining pages are counted from actual database matches.
- `updateGJThread` accepts native numeric `mode` and Base64 `value`, with modes 2 (rename), 4 (single invite), 5 (leave), 8/9 (respond to invite), 12 (delete own message). Unsupported modes fail closed.

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

**Critical:** Member/invite object *trailing optional fields* and eleven-slot group metadata are only partially mapped. In particular, MuchoCore does not yet persist a native group description, emote icon, per-user thread mute/pin state, departed members, native unread counters, or full DM conversation records. These remain zero/empty rather than inventing user data.

Full invitation objects in `getGJThreadInvites` still use MuchoCore's earlier provisional serializer. The client may not parse these successfully. The content and structure of optional system messages are also not implemented.

No real-client Windows or Android packet capture validating these new commits is present. Code only on draft PR #133. Do not merge to `main` or deploy to production solely on the strength of route and unit-test coverage.

## Regression checks

- `php tests/protocol/gd2209-thread-wire.php` validates pure string serialization (five-field message, seven-section response).
- `php tests/application/gd2209-thread-integration.php` on isolated MariaDB validates database persistence, sender ID, membership isolation, Base64 input, native list/header and numeric update modes.
- Existing auth/restriction tests must remain green. Current commits have not been certified by an actual game client.
