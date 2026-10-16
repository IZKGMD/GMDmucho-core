# GD 2.209 group message service

Seven newly observed GD 2.209 endpoint paths are backed by a real, isolated
thread service, but their **native request field names and response byte
format have not yet been validated against the official 2.209 client**.

| Endpoint | MuchoCore-backed operation |
|---|---|
| `getGJThreads.php` | Return authenticated member's threads, paginated |
| `getGJThreadMessages.php` | Return chat messages for authorized members only |
| `getGJThreadMembers.php` | Return members only if caller is in the thread |
| `getGJThreadInvites.php` | Return pending invitations addressed to caller |
| `sendGJThreadMessage.php` | Store bounded message for active member, reject closed thread |
| `searchGJThreadMessages.php` | Bounded per-thread text search; cannot search other threads |
| `updateGJThread.php` | Create, rename, close, invite, accept, decline, leave |

## Provisional mutation contract

The `updateGJThread.php` MuchoCore adapter accepts `accountID`, `gjp2`,
`threadID`, and `action`:

- `action=create`, `title` — creates thread; returns positive thread ID
- `action=rename`, `threadID`, `title` — owner-only rename
- `action=close`, `threadID` — owner-only close
- `action=invite`, `threadID`, `targetAccountID` — owner invites account
- `action=accept|decline`, `inviteID` — recipient handles pending invite
- `action=leave`, `threadID` — member exits; owner cannot abandon a thread

Mutations return `1` on success, `-1` on refusal. Read responses use
a provisional text-key framing (including Base64URL for user-visible text);
the framing **MUST** be replaced after capturing a genuine RobTop response.
This is server-side group messaging functionality, not yet certified native
2.209 UI parity.

## Security and database guarantees

Every method validates `accountID` + account secret through
`AccountAuthenticator`. Thread membership is checked for all message, search
and member reads. Only the owner can rename, invite or close. Only addressed
recipients can accept invitations. Message send verifies membership/closed
state under a DB lock before insertion. Messages have a 4,000-character and
16,000-byte cap. Invitation uniqueness and FK cascades are database-enforced;
maximum confirmed membership is 32 accounts. Blocked pairs cannot be invited.

## Test

`tests/application/gd2209-thread-integration.php` runs only against an
isolated MariaDB instance. It verifies real invitation flows, private reads,
private sends, close behavior, authentication, mutating actions, SQL state and
cascading cleanup.

This change does not silently mark these endpoints `release-perfect` in
`tests/protocol/endpoint-contracts.json`; they remain `unverified` until
native packet captures are linked.
