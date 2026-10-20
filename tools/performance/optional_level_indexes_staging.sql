-- MuchoCore experimental level-list indexes for ISOLATED STAGING only.
-- WARNING: applying ALTER TABLE on production may lock tables, consume disk
-- and slow writes. NOT a migration; never execute automatically on deployment.
-- Evaluate on representative staging data and compare read + write performance.
--
-- Filters: is_deleted=0 AND is_unlisted=0
-- Orders: likes DESC,id DESC; downloads DESC,id DESC; updated_at DESC,id DESC.
-- MariaDB 11.4 can scan ASC composite indexes backward for all-DESC order.
--
-- Rollback, manually on staging ONLY:
-- ALTER TABLE levels DROP INDEX idx_mc_visible_likes;
-- ALTER TABLE levels DROP INDEX idx_mc_visible_downloads;
-- ALTER TABLE levels DROP INDEX idx_mc_visible_updated;

ALTER TABLE levels
  ADD INDEX idx_mc_visible_likes (is_deleted, is_unlisted, likes, level_id);

ALTER TABLE levels
  ADD INDEX idx_mc_visible_downloads (is_deleted, is_unlisted, downloads, level_id);

-- Preserve featured semantics: featured > 0 is a range predicate and placing
-- it before updated_at would break the useful ORDER BY index prefix. Keep it
-- in WHERE while traversing the visible updated_at index.
ALTER TABLE levels
  ADD INDEX idx_mc_visible_updated (is_deleted, is_unlisted, updated_at, level_id);
