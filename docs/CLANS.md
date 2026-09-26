# MuchoCore Clans

MuchoCore provides a GDPS-local clan system as a first-class server feature.

## Features

- Unique clan name and short tag.
- One clan membership per account.
- Owner, officer and member roles.
- Open or invite-only clans.
- Configurable member limit.
- Seven-day invitations.
- Join, leave, invite, accept, decline and revoke invitation operations.
- Join applications for invite-only clans, with a seven-day expiry.
- Owner/officer review of pending join applications.
- Explicit owner/officer/member permission matrix.
- Owner-only settings changes, ownership transfer and permanent clan deletion.
- Officer/member kick rules with owner protection.
- Clan bans that remove members and invalidate pending invitations.
- Live clan statistics aggregated from current member profiles.
- Clan rankings for total stars, demons, creator points, published levels and membership.
- Clan management audit events in the existing `audit_logs` table.
- A player-facing clan directory at `/dashboard/clans.php` on every MuchoCore GDPS.
- Clan tags are rendered in standard Geometry Dash user-name response fields without changing the real account username.

## Data model

The base migration `020_clans.php` creates:

```text
mucho_clans
mucho_clan_members
mucho_clan_invites
mucho_clan_applications
```

Clan statistics are computed live from `mucho_clan_members` joined to player `profiles`; no duplicated per-clan counters are required.

The follow-up migration `20260926_002_clans_v2.php` adds:

```text
mucho_clan_bans
```

The account's real `accounts.username` is never rewritten when a clan tag is used.

## Clan lifecycle

A clan can be created, populated through open joins or invitations, managed by owner/officer roles, transferred to a new owner, and finally disbanded by the owner. Ownership transfer makes the selected member the new owner and the previous owner an officer. The owner cannot leave or be kicked/banned. Officers cannot remove or ban other officers.

## Concurrency and integrity

Membership writes lock the clan row and re-check the member limit inside the transaction. Invitation acceptance performs its membership and capacity checks again immediately before insertion. The unique account membership key remains the final integrity guard.

Clan bans are checked for both open joins and direct invitations. Applying a ban removes the member and pending invitations in the same transaction.

## In-game display

MuchoCore uses the existing Geometry Dash username fields rather than adding a client-side clan protocol. A compatible unmodified client can therefore show the clan prefix as part of the normal player name.

A member of a clan named:

```text
Mucho Squad
```

with tag:

```text
MUCH
```

may be rendered by the normal GD profile/social responses as:

```text
[MUCH]PlayerName
```

The display value is capped to the normal Geometry Dash username field length. The underlying username, account ID and authentication credentials remain unchanged.

The tag is included in player profiles, player search, leaderboards, friend/block lists, friend requests, messages and comment user data where MuchoCore already returns a player name.

## API

All clan management endpoints use `POST` and the existing `accountID` plus Geometry Dash credential authentication.

```text
POST /api/clans/create
POST /api/clans/my
POST /api/clans/get
POST /api/clans/search
POST /api/clans/join
POST /api/clans/leave
POST /api/clans/stats
POST /api/clans/rankings
POST /api/clans/permissions
POST /api/clans/invite
POST /api/clans/apply
POST /api/clans/applications
POST /api/clans/applications/incoming
POST /api/clans/application/accept
POST /api/clans/application/decline
POST /api/clans/application/cancel
POST /api/clans/invite/accept
POST /api/clans/invite/decline
POST /api/clans/kick
POST /api/clans/role
POST /api/clans/invites
POST /api/clans/invite/revoke
POST /api/clans/settings
POST /api/clans/transfer
POST /api/clans/disband
POST /api/clans/delete
POST /api/clans/ban
POST /api/clans/unban
POST /api/clans/bans
```

Common fields:

```text
accountID
gjp / gjp2
```

Create fields:

```text
clanName
clanTag
clanDescription
clanOpen
clanMaxMembers
```

Member management fields:

```text
clanID
targetAccountID
role
inviteID
query
```

Responses are JSON and use:

```json
{
  "ok": true,
  "data": {}
}
```

Errors return:

```json
{
  "ok": false,
  "error": "..."
}
```


## Stats, rankings and permissions

Ranking metrics currently available:

```text
stars
moons
demons
diamonds
secret_coins
user_coins
creator_points
levels
members
```

The player-facing dashboard displays live top-10 tables for total stars, total demons, total creator points and member count. Public clan profiles also display total stars, demons, creator points and published level count.

The explicit permission matrix is:

| Role | Permissions |
| --- | --- |
| owner | view, view_stats, invite, manage_invites, manage_applications, kick, manage_bans, manage_roles, settings, transfer, delete |
| officer | view, view_stats, leave, invite, manage_invites, manage_applications, kick, manage_bans |
| member | view, view_stats, leave |

`/api/clans/permissions` returns the authenticated account's current role and effective permissions. `/api/clans/delete` permanently deletes the clan through the same owner-only transaction used by the existing disband operation.

## Compatibility

Clans do not replace or modify existing Geometry Dash account identifiers. Older clients can continue using the same account, level and social endpoints.

The in-game tag display is implemented by decorating the name returned by existing user/profile/comment encoders. No dedicated clan protocol is required for clients that already display the standard user-name field.

The player dashboard provides clan discovery, creation, open-clan joining, private-clan applications and membership status. Applications expire after seven days and are reviewed by the clan owner or officers.

The dashboard is intentionally GDPS-local: each installation reads its own clan database, so clan names and tags are scoped to that server. The existing authenticated JSON API remains the canonical management contract for officer/owner operations and future richer UIs.