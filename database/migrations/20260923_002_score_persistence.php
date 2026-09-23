<?php

declare(strict_types=1);

use PDO;

/**
 * MuchoCore score persistence hardening.
 *
 * The score controllers are part of the live protocol, so their tables must
 * exist on a completely fresh installation as well as on an older server.
 * The unique keys also turn "SELECT then INSERT" races into deterministic
 * upserts.
 */
return static function (PDO $pdo): void {
    $tableExists = static function (string $table) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table
             LIMIT 1'
        );
        $stmt->execute(['table' => $table]);

        return (bool)$stmt->fetchColumn();
    };

    $ensureUnique = static function (
        string $table,
        string $index,
        string $definition
    ) use ($pdo, $tableExists): void {
        if (!$tableExists($table)) {
            return;
        }

        $stmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND index_name = :index
             LIMIT 1'
        );
        $stmt->execute([
            'table' => $table,
            'index' => $index,
        ]);

        if ($stmt->fetchColumn() !== false) {
            return;
        }

        $pdo->exec("ALTER TABLE `{$table}` {$definition}");
    };

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_level_scores (
    score_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    level_id BIGINT UNSIGNED NOT NULL,
    is_daily TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    daily_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    coins TINYINT UNSIGNED NOT NULL DEFAULT 0,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    play_time INT UNSIGNED NOT NULL DEFAULT 0,
    progresses LONGTEXT NOT NULL,
    created_at BIGINT UNSIGNED NOT NULL,
    updated_at BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (score_id),
    UNIQUE KEY uq_mucho_level_score (
        account_id,
        level_id,
        is_daily
    ),
    KEY idx_mucho_level_scores_level (
        level_id,
        is_daily,
        percent
    ),
    KEY idx_mucho_level_scores_updated (
        updated_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_platformer_scores (
    score_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    level_id BIGINT UNSIGNED NOT NULL,
    time_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
    points BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at BIGINT UNSIGNED NOT NULL,
    updated_at BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (score_id),
    UNIQUE KEY uq_mucho_platformer_score (
        account_id,
        level_id
    ),
    KEY idx_mucho_platformer_scores_level_time (
        level_id,
        time_ms
    ),
    KEY idx_mucho_platformer_scores_level_points (
        level_id,
        points
    ),
    KEY idx_mucho_platformer_scores_updated (
        updated_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    /*
     * Older development snapshots may have created the tables without the
     * unique constraint. Collapse duplicates before attempting to add it.
     */
    if ($tableExists('mucho_level_scores')) {
        $pdo->exec(<<<'SQL'
DELETE s1
FROM mucho_level_scores s1
JOIN mucho_level_scores s2
  ON s1.account_id = s2.account_id
 AND s1.level_id = s2.level_id
 AND s1.is_daily = s2.is_daily
 AND (
      s1.percent < s2.percent
      OR (
          s1.percent = s2.percent
          AND s1.updated_at < s2.updated_at
      )
      OR (
          s1.percent = s2.percent
          AND s1.updated_at = s2.updated_at
          AND s1.score_id < s2.score_id
      )
 )
SQL);
    }

    if ($tableExists('mucho_platformer_scores')) {
        $pdo->exec(<<<'SQL'
DELETE s1
FROM mucho_platformer_scores s1
JOIN mucho_platformer_scores s2
  ON s1.account_id = s2.account_id
 AND s1.level_id = s2.level_id
 AND s1.score_id < s2.score_id
SQL);
    }

    $ensureUnique(
        'mucho_level_scores',
        'uq_mucho_level_score',
        'ADD UNIQUE KEY uq_mucho_level_score (account_id, level_id, is_daily)'
    );

    $ensureUnique(
        'mucho_platformer_scores',
        'uq_mucho_platformer_score',
        'ADD UNIQUE KEY uq_mucho_platformer_score (account_id, level_id)'
    );
};
