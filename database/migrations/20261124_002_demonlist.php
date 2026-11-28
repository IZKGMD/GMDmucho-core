<?php
declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS mucho_demonlist_levels (
        level_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        position SMALLINT UNSIGNED NULL,
        is_published TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_demonlist_position (position),
        KEY idx_demonlist_position (is_published, position, level_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS mucho_demonlist_records (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        level_id BIGINT UNSIGNED NOT NULL,
        video_id CHAR(11) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at TIMESTAMP NULL DEFAULT NULL,
        reviewed_by BIGINT UNSIGNED NULL DEFAULT NULL,
        UNIQUE KEY uq_demonlist_record (account_id,level_id),
        KEY idx_demonlist_queue (status,id),
        KEY idx_demonlist_level_records (level_id,status,submitted_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
