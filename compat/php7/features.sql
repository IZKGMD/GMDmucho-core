-- Native PHP7 additive upgrade. Import into the EXISTING native database using phpMyAdmin.
-- Does not alter, delete, replace or import player data.

CREATE TABLE IF NOT EXISTS php7_comments (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS php7_votes (
            type TINYINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            value TINYINT NOT NULL,
            PRIMARY KEY(type,item_id,account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS php7_cloud_saves (
            account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            save_data MEDIUMBLOB NOT NULL,
            sha256 CHAR(64) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
