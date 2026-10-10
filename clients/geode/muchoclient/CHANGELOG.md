# v0.6.0

- Persistent personal nickname colors and a shared clan prefix color controlled by its owner.
- Native Colors editor with twelve presets, custom HEX, live preview, default reset and authenticated Save.
- Colored names in player profiles, comments, player rankings and clan members; colored tags in clan listings and rankings.
- Batched public color lookups with a bounded cache, lifecycle-safe label references and retry backoff.
- Automatic color refresh after clan changes and saved appearance; preserves regular game accounts and username protocol.

Update MuchoCore as well for the appearance API and its automatic database migration.

# MuchoClient v0.5.0

- Add CLANS ranking button to the CreatorLayer menu, above Scores.
- Add seven clan leaderboard categories, pagination, own-clan rank/highlight and clickable clan rows.
- Compute current member totals on the core, excluding banned/inactive accounts.
- Display totals beyond 32-bit and preserve button animation baselines.
- Test metric sorting, stable global ranks, pagination, live membership/stat changes and empty rankings against MariaDB.

# MuchoClient v0.4.0

- Full clan management: member lists, roles, kicking, banning/unbanning,
  invitation management, clan settings, ownership transfer and disbanding.
- Open and invite-only clan creation with matching server-side validation.
- Search pagination and received/sent invitation lists.
- Consistent button dimensions after pressing and releasing.
- Input retained on errors, requests protected against repeated clicks,
  and callbacks cancelled when the clan window closes.
- Visible client version and native clan routing by the core API entrypoint.

Update MuchoCore as well for sent invitations and paginated search.
