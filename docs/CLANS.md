# MuchoCore Clans

MuchoCore includes a lightweight clan system that sits beside the existing account and social services.

## Features

- Unique clan name and short tag.
- One clan membership per account.
- Owner, officer and member roles.
- Open or invite-only clans.
- Configurable member limit.
- Seven-day invitations.
- Join, leave, invite, accept, decline, kick and role-management operations.
- Clan tags are rendered in standard Geometry Dash user-name response fields without changing the real account username.

## Data model

The migration `020_clans.php` creates:

```text
mucho_clans
mucho_clan_members
mucho_clan_invites
```

The account's real `accounts.username` is never rewritten when a clan tag is used.

## In-game display

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

The tag is included in player profile, player search, leaderboard and comment user data where MuchoCore already returns a player name.

## API

All clan management endpoints use `POST` and the existing `accountID` plus Geometry Dash credential authentication.

```text
POST /api/clans/create
POST /api/clans/my
POST /api/clans/get
POST /api/clans/search
POST /api/clans/join
POST /api/clans/leave
POST /api/clans/invite
POST /api/clans/invite/accept
POST /api/clans/invite/decline
POST /api/clans/kick
POST /api/clans/role
POST /api/clans/invites
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

## Compatibility

Clans do not replace or modify existing Geometry Dash account identifiers. Older clients can continue using the same account, level and social endpoints.

The in-game tag display is implemented by decorating the name returned by existing user/profile/comment encoders. No dedicated clan protocol is required for clients that already display the standard user-name field.

Management uses the MuchoCore JSON API so a future custom GD client, web panel or external community UI can expose full clan controls.