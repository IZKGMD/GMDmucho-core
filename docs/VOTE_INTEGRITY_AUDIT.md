# PHP 8 vote integrity audit

This diagnostic checks the `likes` history against cached net scores for levels, level comments, profile comments, and level lists. It does **not** repair or change any data.

## Usage

From the installed MuchoCore project directory (PHP 8.2+):

```bash
php bin/mucho-audit-votes.php
php bin/mucho-audit-votes.php --max-targets=20000 --max-votes=50000 --batch=200
```

Defaults: inspect up to 5,000 targets **of each type** and 20,000 vote rows, in batches of 200. Maximum configured cap per category is 100,000. Outputs aggregate JSON only (no usernames, IPs or message bodies). A limited scan is **not** a clean bill of health for all historical data.

Exit codes: `0` = complete and clean, `1` = runtime or schema error, `2` = anomalies require review, `3` = only a partial scan was performed, `64` = invalid arguments.

## What it checks

- `stored_score` versus `SUM(+1 for likes, -1 for dislikes)` on each sampled object.
- Orphaned vote rows that refer to deleted/nonexistent targets.
- Authenticated votes on the voter's own objects.
- Unexpected `is_like` values and unrecognized target types.
- Presence of the `uq_like_item_actor (item_id,type,account_id,ip)` uniqueness index, needed to keep anonymous IP voters distinct.

The scan uses an isolated REPEATABLE READ transaction for a stable snapshot and never issues write queries. The auditor intentionally **does not auto-fix** any mismatch because historical imports, deleted objects, or damaged vote rows may make automatic corrections destructive. Investigate a discrepancy, back up the database and verify the source of truth before making any manual repairs.

The last check of existing live records through GitHub issue #205 was bounded to the first 250 targets of each type. That check cannot certify all historical votes.

## Release procedure

1. Run PHP 8 CI including `tests/application/like-integrity-integration.php` against isolated MariaDB.
2. Run the bounded read-only audit and inspect `complete`, `needs_review` and all counters.
3. Validate a disposable comment from two distinct accounts in a real GD 2.209 client: dislike, duplicate dislike, switch to like, duplicate like, switch back, and attempt a self-vote.
4. Check that the game's score and database counter agree, that a rejected vote cannot be disguised as anonymous, and that the level counter is unchanged if a comment request also included `levelID`.
5. Promote only after the regression and native client checks pass. PHP 7 port follows afterwards; Go is last.

This audit is a diagnostic, not a replacement for a backup, consistent migration or real-client evidence.
