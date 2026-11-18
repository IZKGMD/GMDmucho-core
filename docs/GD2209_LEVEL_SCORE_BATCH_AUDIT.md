# GD 2.209 level-score endpoint: batched leaderboard and acceptance checks

**2026-11-18** — Compared MuchoCore's `src/Score/LevelScoreController.php` with
its own `PlatformerScoreController.php` and the client routes from the
verified 19 October GD 2.209 Windows executable.

## Confirmed source-level issue

`getGJLevelScores211` and its legacy aliases call `regular()`, which writes a
score and then renders a leaderboard. The previous `leaderboard()` queried up
to 100 `mucho_level_scores` rows and invoked
`UserRepository::getProfileByTarget()` **once per row**.

That routine reads/inserts the profile and uses extra SQL for profile counts
and clan/social fields which the score response **does not serialize**.
On a busy server this is an avoidable read/write amplification of a hot route.

Platformer score leaderboards already use a single joined query, offering a
validated architectural reference inside MuchoCore itself.

## Fix

Use one bounded SQL query with indexed joins from `mucho_level_scores` to
`accounts` and `profiles`, retrieving **only the score protocol fields**:
username, user/account IDs, cube, colors, icon type, special, percentage,
coins and update time.

- The existing authentication and atomic score upsert are unchanged.
- Enforce active/unbanned account visibility before the `LIMIT 100`.
- Preserve personal friends (`type=0`), public (`type=1`) and recent
  (`type=2`) selection.
- Preserve the optional anticheat quarantine exclusion before limiting.
- Keep original percent and timestamp sort and existing string field order.
- A legacy account missing a profile uses explicit, identical default icon
  metadata but no longer creates profiles in a read-only leaderboard.
- PHP7's compiled score controller is updated independently; the original
  PHP8 fingerprint in `compat/php7/core/sources.json` is synchronized.

## Evidence and limits

- `tests/application/gd2209-level-score-batch-sqlite.php` counts actual
  prepared statements for 100 scores, validates output format, default
  profile values, limit, banned/inactive exclusion, quarantine and recency.
- `tests/compat/php7-native-level-score-leaderboard-mysql.php` runs the
  compiled PHP7 controller on a disposable MariaDB database, covering
  friends, bans, profile absence, quarantine and recent filtering.
- Both are included in CI. This reduces SQL queries on that read path
  from up to hundreds to **one prepared SELECT**, but is **not** a measured
  5v.pl latency or CPU benchmark.
- Real Windows and Android GD 2.209 client signoff is still pending; this
  patch does not assert that all 166 manifest endpoints are functionally
  certified or that PHP7 is security-supported.

Next audit targets: platformer native response fixtures, social/thread
consistency, cloud-save reconstruction and end-to-end provider tests.
