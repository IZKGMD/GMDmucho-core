# Geometry Dash 2.209: score retrieval must be read-only

Source reference: [GD Docs: getGJLevelScores211](https://boomlings.dev/endpoints/levels/getGJLevelScores211) (endpoint parameters and semantics). Observed native Windows route `getGJLevelScores211.php` in the verified client binary (SHA256 `4eee06d5c2e2213b4ba2b1f6233dd426bbdd0e9b29d7c375e499516b6f8f69d5`).

## Defect

The previous `LevelScoreController::regular()` treated an omitted
`percent` as zero and unconditionally invoked `saveScore()`:

- Merely opening a level's leaderboard could create an artificial 0%
  progress record or update an existing record's metadata.
- An omitted `type` defaulted to `1` (global), while the native
  endpoint's default is `0` (friends).
- An explicit malformed value such as `percent=garbage` could be
  coerced to zero and submitted.

These problems affect `getGJLevelScores211` and any older route aliases
that reuse its same controller.

## New behavior

1. Authenticate the requesting account and verify the level exists.
2. If `percent` is **absent**, read the requested leaderboard without
   writing a new score or score integrity telemetry.
3. If `percent` is explicitly present, require an integer 0..100.
   A legitimate **explicit zero** is still a write.
4. When `type` is missing, use friends (`0`) rather than global
   (`1`); explicit global (`1`) and recent (`2`) remain supported.
5. Reuse the same N+1-free leaderboard query from the preceding batch
   change; continue excluding banned accounts, applying privacy scoping
   and optional score quarantine.

Both canonical PHP8 and the compiled native PHP7 score controller were
updated, along with the PHP7 original source fingerprint.

## Acceptance evidence

`tests/compat/php7-native-levelscore-readonly-mysql.php` runs on an
isolated MariaDB fixture with these scenarios:

- authenticated read request without `percent` does not change the
  row count, stored timestamps or stored progress;
- default friends list contains only the requester and actual friends;
- a first-time request without `percent` does not create a score;
- explicit `percent=0` inserts exactly one score;
- malformed percentage values are rejected with no new score;
- invalid credentials are rejected even for read-only requests.

It runs under PHP8 in Validate and compiled native PHP7 under both PHP
7.0 and 8.3 in Native PHP7 CI. Passing these tests is not a substitute
for a real GD 2.209 client run on 5v.pl. The PHP7.0 runtime itself is
end-of-life and cannot be security-certified.
