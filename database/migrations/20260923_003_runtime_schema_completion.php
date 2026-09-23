<?php

declare(strict_types=1);

use PDO;

/**
 * MuchoCore runtime schema completion.
 *
 * Several protocol modules pre-date the unified migration system. This migration
 * makes every table used by the current runtime available on a clean install.
 * Existing tables are left intact.
 */
return static function (PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_cloud_saves (
    account_id BIGINT UNSIGNED NOT NULL,
    ciphertext LONGBLOB NOT NULL,
    nonce VARBINARY(12) NOT NULL,
    auth_tag VARBINARY(16) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    backed_up_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id),
    KEY idx_mucho_cloud_saves_sha (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_cloud_save_revisions (
    history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    ciphertext LONGBLOB NOT NULL,
    nonce VARBINARY(12) NOT NULL,
    auth_tag VARBINARY(16) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    original_backed_up_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (history_id),
    UNIQUE KEY uq_mucho_cloud_save_revision (account_id, revision),
    KEY idx_mucho_cloud_save_revisions_account (account_id, revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    to_account_id BIGINT UNSIGNED NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    is_read TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    is_sender_deleted TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    is_receiver_deleted TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_messages_sender (account_id, is_sender_deleted, id),
    KEY idx_messages_receiver (to_account_id, is_receiver_deleted, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS friends (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    friend_account_id BIGINT UNSIGNED NOT NULL,
    is_new TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_friends_pair (account_id, friend_account_id),
    KEY idx_friends_reverse (friend_account_id, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS friend_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    to_account_id BIGINT UNSIGNED NOT NULL,
    comment VARCHAR(1000) NOT NULL DEFAULT '',
    is_read TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_friend_request_pair (account_id, to_account_id),
    KEY idx_friend_requests_target (to_account_id, is_read, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    blocked_account_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_blocks_pair (account_id, blocked_account_id),
    KEY idx_blocks_reverse (blocked_account_id, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_level_lists (
    list_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    list_name VARCHAR(64) NOT NULL,
    list_desc VARCHAR(500) NOT NULL DEFAULT '',
    list_version INT UNSIGNED NOT NULL DEFAULT 1,
    level_ids MEDIUMTEXT NOT NULL,
    difficulty INT NOT NULL DEFAULT 0,
    original_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    unlisted TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    downloads BIGINT UNSIGNED NOT NULL DEFAULT 0,
    likes BIGINT NOT NULL DEFAULT 0,
    featured TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    stars INT UNSIGNED NOT NULL DEFAULT 0,
    count_for_reward INT UNSIGNED NOT NULL DEFAULT 0,
    created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (list_id),
    KEY idx_mucho_level_lists_likes (unlisted, likes, list_id),
    KEY idx_mucho_level_lists_downloads (unlisted, downloads, list_id),
    KEY idx_mucho_level_lists_owner (account_id, list_id),
    KEY idx_mucho_level_lists_created (unlisted, created_at, list_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_level_reports (
    report_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    level_id BIGINT UNSIGNED NOT NULL,
    reporter_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (report_id),
    UNIQUE KEY uq_mucho_level_report (level_id, reporter_hash),
    KEY idx_mucho_level_reports_level (level_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
INSERT IGNORE INTO roles (code, name, priority)
VALUES ('helper', 'Helper', 10)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_reward_state (
    account_id BIGINT UNSIGNED NOT NULL,
    small_last_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
    small_count INT UNSIGNED NOT NULL DEFAULT 0,
    big_last_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
    big_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_challenge_pool (
    challenge_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type TINYINT UNSIGNED NOT NULL,
    amount INT UNSIGNED NOT NULL,
    reward INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (challenge_id),
    KEY idx_mucho_challenge_pool_active (active, challenge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Give a clean installation a usable default challenge pool.
    $count = (int)$pdo->query(
        'SELECT COUNT(*) FROM mucho_challenge_pool WHERE active=1'
    )->fetchColumn();

    if ($count < 3) {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO mucho_challenge_pool
                (challenge_id,type,amount,reward,name,active)
             VALUES
                (:id1,1,1000,10,:name1,1),
                (:id2,1,5000,25,:name2,1),
                (:id3,2,10,15,:name3,1),
                (:id4,2,25,30,:name4,1),
                (:id5,3,3,25,:name5,1),
                (:id6,3,10,50,:name6,1)'
        );
        $stmt->execute([
            'id1' => 1,
            'id2' => 2,
            'id3' => 3,
            'id4' => 4,
            'id5' => 5,
            'id6' => 6,
            'name1' => 'Collect 1,000 Orbs',
            'name2' => 'Collect 5,000 Orbs',
            'name3' => 'Collect 10 Coins',
            'name4' => 'Collect 25 Coins',
            'name5' => 'Earn 3 Stars',
            'name6' => 'Earn 10 Stars',
        ]);
    }

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_gauntlets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    level1 BIGINT UNSIGNED NOT NULL DEFAULT 0,
    level2 BIGINT UNSIGNED NOT NULL DEFAULT 0,
    level3 BIGINT UNSIGNED NOT NULL DEFAULT 0,
    level4 BIGINT UNSIGNED NOT NULL DEFAULT 0,
    level5 BIGINT UNSIGNED NOT NULL DEFAULT 0,
    enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_mucho_gauntlets_enabled (enabled, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_daily_rotation (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    level_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(16) NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_mucho_daily_rotation_active (
        kind, enabled, starts_at, ends_at
    ),
    KEY idx_mucho_daily_rotation_level (level_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_map_packs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(64) NOT NULL,
    levels TEXT NOT NULL,
    stars INT UNSIGNED NOT NULL DEFAULT 0,
    coins INT UNSIGNED NOT NULL DEFAULT 0,
    difficulty INT NOT NULL DEFAULT 0,
    color1 INT NOT NULL DEFAULT 0,
    color2 INT NOT NULL DEFAULT 0,
    enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_mucho_map_packs_enabled (enabled, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
