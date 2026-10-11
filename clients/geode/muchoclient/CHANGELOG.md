# v0.8.2

- Select the exact cover area on the full photo with a visible 3:1 rectangle, shaded outside area and composition grid.
- Drag the rectangle to move it or its corner handles to resize it; tap outside the rectangle to recenter, with live preview and Reset.
- Show the saved cover size (960x320) and avatar size (256x256) directly in the editor.
- Share one decoded image texture between the selection view and cropped preview; honor pixel coordinates on scaled displays.
- Reuse the existing authenticated profile API and crop limits; no new server migration.

# v0.8.1

- Keep the normal GD profile cover inside its actual panel bounds, including moved or scaled profiles.
- Compact native avatar with a clean frame and a profile button anchored to the username row.
- Full 3:1 cover in the Mucho card, separate avatar/name row and six evenly spaced statistics.
- White avatar frame, consistent margins and buttons clear of the statistics and footer.
- Client layout update only; existing saved images and v0.8.0 profile API remain compatible.

# v0.8.0

- Upload personal avatar and profile cover from the system JPG/PNG file picker on Windows and Android.
- Live crop preview with position, zoom and clockwise rotation; authenticated save and independent removal.
- Public profile card with player colors and server statistics, plus avatar/cover strip in the normal GD profile.
- Fresh server-encoded JPEGs, strict input dimensions, versioned downloads, bounded image cache and stale-session protection.
- Persistent images are included in ordinary database backups. Server update adds the migration and PHP GD extension.

# v0.7.0

- Clan quests: owners and officers can start one stars goal and one demons goal concurrently. Each runs for seven days.
- Shared progress bars, quest history, paginated contribution ranking and cancellation confirmation.
- Server-side score baselines and high-water marks exclude prior statistics and prevent duplicate/reset/rejoin contributions. Leaving preserves earned progress.
- Private clan activity records creation, membership changes, roles, ownership, settings and quest outcomes.
- Clan requests now reject stale account/server responses. All new buttons preserve their press-animation baseline.

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
