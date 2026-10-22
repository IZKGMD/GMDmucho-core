<?php
/** Native PHP 7.0 feature schema. Additive; never alters or truncates core tables. */
declare(strict_types=1);

function mucho7_feature_schema()
{
    return array(
        "CREATE TABLE IF NOT EXISTS php7_comments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            kind TINYINT UNSIGNED NOT NULL,
            level_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            account_id BIGINT UNSIGNED NOT NULL,
            content TEXT NOT NULL,
            percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
            likes INT NOT NULL DEFAULT 0,
            is_spam TINYINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_comments_level(kind,level_id,id),
            KEY idx_comments_account(kind,account_id,id),
            KEY idx_comments_cooldown(account_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS php7_votes (
            type TINYINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            value TINYINT NOT NULL,
            PRIMARY KEY(type,item_id,account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS php7_cloud_saves (
            account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            save_data MEDIUMBLOB NOT NULL,
            sha256 CHAR(64) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function mucho7_upgrade_features(PDO $pdo)
{
    // Run explicitly during installation/upgrade, never on an API request.
    foreach (mucho7_feature_schema() as $sql) {
        $pdo->exec($sql);
    }
}
