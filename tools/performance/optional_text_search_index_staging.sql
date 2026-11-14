-- Optional PHP 8 text-search count accelerator for ISOLATED STAGING only.
-- The current GD type=0/15 search uses l.name LIKE '%term%' and exact
-- visibility predicates. The leading % prevents a B-tree SEEK; this index
-- may still reduce COUNT(*) I/O by covering the scan and supporting likes DESC ordering without reading
-- levels.level_data (LONGTEXT) or fetching clustered level rows.
--
-- Do not deploy to production automatically. On 10k+ representative rows:
-- 1. compare EXPLAIN text_search_count/page before and after;
-- 2. compare exact returned IDs, GD wire body and total counts;
-- 3. compare p50/p95/p99 across repeated warm/cold A/B runs;
-- 4. measure concurrent level uploads, likes and downloads too.
-- The optimizer may decline this index; no speedup is guaranteed.
--
-- Rollback ON STAGING ONLY:
-- ALTER TABLE levels DROP INDEX idx_mc_text_visible_ranked;

ALTER TABLE levels
  ADD INDEX idx_mc_text_visible_ranked (is_deleted, is_unlisted, unlisted2, likes, level_id, game_version, name);
