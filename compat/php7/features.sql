-- MuchoCore native shared additive feature upgrade.

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

CREATE TABLE IF NOT EXISTS php7_user_details (
            account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            payload TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS php7_friend_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL,
            to_account_id BIGINT UNSIGNED NOT NULL,
            comment VARCHAR(192) NOT NULL DEFAULT '',
            is_read TINYINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_request(account_id,to_account_id),
            KEY idx_request_inbox(to_account_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS php7_friends (
            account_id BIGINT UNSIGNED NOT NULL,
            friend_account_id BIGINT UNSIGNED NOT NULL,
            is_new TINYINT NOT NULL DEFAULT 1,
            PRIMARY KEY(account_id,friend_account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS php7_blocks (
            account_id BIGINT UNSIGNED NOT NULL,
            blocked_account_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY(account_id,blocked_account_id),
            KEY idx_blocked(blocked_account_id,account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS php7_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL,
            to_account_id BIGINT UNSIGNED NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            is_read TINYINT NOT NULL DEFAULT 0,
            is_sender_deleted TINYINT NOT NULL DEFAULT 0,
            is_receiver_deleted TINYINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_message_inbox(to_account_id,is_receiver_deleted,id),
            KEY idx_message_outbox(account_id,is_sender_deleted,id),
            KEY idx_message_rate(account_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS php7_admin_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(40) NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            details VARCHAR(512) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_audit_created(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS php7_level_details (
            level_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            level_info TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS php7_downloads (
            level_id BIGINT UNSIGNED NOT NULL,
            visitor_hash CHAR(64) NOT NULL,
            downloaded_on DATE NOT NULL,
            PRIMARY KEY(level_id,visitor_hash,downloaded_on),
            KEY idx_download_cleanup(downloaded_on)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Administrator factors are separate from game login.
CREATE TABLE IF NOT EXISTS php7_admin_security (
            account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            totp_secret VARCHAR(64) NULL,
            last_step BIGINT NOT NULL DEFAULT -1,
            auth_version INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS php7_admin_recovery_codes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            used_at DATETIME NULL,
            KEY idx_recovery_account(account_id,used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
