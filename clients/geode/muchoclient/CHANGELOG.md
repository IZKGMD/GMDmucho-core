# v0.9.5

- Smiley buttons in free-text input fields open an emoji menu.
- Picking an emoji inserts at the cursor using native input filtering and length limits.
- Numeric and password fields are excluded.

# v0.9.4

- Bundle 4009 Twemoji 17 color emoji in 16 offline atlas pages with source hash verification and attribution.
- Render whole Unicode emoji sequences in bitmap labels and plain multiline comments/descriptions; preserve source text.
- Extend only eligible free-text input filters temporarily for known emoji bytes; preserve password/numeric filters.
- Add Emoji Support setting, a 48-choice copy/paste picker and UTF-8/emoji-safe Mucho clan text truncation.
- Keep ASCII labels on the native path, cap per-label sprites and parse on text changes. No server migration.
- Reuse emoji sprites across layout changes, batch by atlas page and map input cursor positions to complete emoji slots.
- Native rich-text descriptor and batched level-text paths remain unchanged; device UI/FPS validation remains pending.

# v0.9.3

- Add viewport-fitted opening/closing transitions to Mucho windows; preserve unsaved-image confirmation and cancel requests before closing.
- Add soft press/release feedback for Mucho buttons, staggered module/ranking/palette rows, and animated clan activity entries.
- Fade profile artwork after loading and animate profile counters with a fixed glyph-update budget.
- Interpolate clan/Daily Quest progress, animate Quest XP, and show a bounded star effect only for newly confirmed rewards.
- Show request-bound loading dots; add Smooth UI Animations and Reduced Motion settings.
- Suppress extra animation during gameplay and preserve the 0.9.2 performance work. No server migration; one Windows/Android package.

# v0.9.2

- Share public profile requests and decoded avatar/cover textures between menu, profiles and ranking rows.
- Bound concurrency to two metadata requests and two image jobs; limit decoded texture cache to 8 MiB / 32 entries.
- Defer decorative responses and GPU uploads while a PlayLayer exists; reject stale, closed or changed-server subscribers.
- Repaint colored names only when their appearance revision changes; adding a row no longer repaints all previous rows.
- Refresh Daily Quests countdown once a second, with no per-frame text update.
- Preserve native fallback icons and cached textures across scene transitions. No server migration; one Windows/Android package.

# v0.9.1

- Fit Daily Quests inside landscape game viewports, with space for its title, close button, three cards and footer.
- Keep clan-prefixed ranking names at their original left edge and fit them before the actual statistic bounds; resize the native clickable name area accordingly.
- Show uploaded MuchoCore avatars in player rankings, with native-icon fallback during loading and for accounts without an avatar.
- Cancel closed/recycled row requests and use public account-bound metadata plus the existing versioned, bounded image cache.
- No additional server migration after 0.9.0; one combined Windows/Android `.geode`.

# v0.9.0

- Personal Daily Quests with three rotating UTC goals, progress bars, claim buttons and server-held Quest XP.
- Quest rewards are retried safely; the server commits the claim marker and XP atomically.
- Paginated extension navigation reaches every advertised module instead of the first five.
- Per-feature minimum client version support and bounded quest response validation.
- Clans, clan quests, leaderboards, colors, profiles and avatars remain in the same `izkgmd.muchoclient` package.
- One combined `.geode` includes Windows x64, Android ARM64 and Android ARMv7 builds.

# v0.8.4

- Show your uploaded avatar inside the circular profile button beneath your username on the main menu.
- Keep the original button, rim, click action, hit area and press-animation scale.
- Fall back to the normal profile icon until decoding succeeds, or when the avatar is removed or unavailable.
- Refresh after avatar saves/removal and periodically; clear stale imagery on account/server changes and ignore late responses.
- Use the existing bounded, versioned image cache and public profile APIs; no additional server migration.

# v0.8.3

- Windows crop controls: smooth 100–300% zoom slider, mouse wheel over the photo, arrow-key fine adjustments and faster Shift + arrows.
- Plus/minus zoom, R rotates clockwise and 0 resets; a help button lists mouse and keyboard controls.
- Keep the selected source point centred when zooming or rotating; clamp the crop at the photo edges.
- Larger photo selection view, separate control rows, source crop dimensions and matching live preview.
- Save stays disabled until decoding finishes; confirm before discarding an unsaved image. Modal dialogs block crop input.
- Existing avatars, covers, clan features and authenticated server APIs remain compatible; no server migration.

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


