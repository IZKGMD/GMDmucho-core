<?php
declare(strict_types=1);
return [
    "INSERT IGNORE INTO server_settings(setting_key,setting_value) VALUES
        ('media.images.enabled','0'),('media.moderation.mode','manual')",
    "CREATE TABLE IF NOT EXISTS mucho_message_media (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        scope ENUM('message','room') NOT NULL,
        target_id BIGINT UNSIGNED NOT NULL,
        message_id BIGINT UNSIGNED NULL,
        subject VARCHAR(80) NOT NULL DEFAULT '',
        caption VARCHAR(1000) NOT NULL DEFAULT '',
        source_hash CHAR(64) NOT NULL,
        image_hash CHAR(64) NOT NULL,
        width SMALLINT UNSIGNED NOT NULL,
        height SMALLINT UNSIGNED NOT NULL,
        image_data MEDIUMBLOB NULL,
        state ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        reason VARCHAR(128) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at TIMESTAMP NULL,
        KEY idx_media_owner(account_id,created_at),
        KEY idx_media_message(message_id,state),
        KEY idx_media_review(state,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS mucho_room_chat (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        room_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL,
        content VARCHAR(1000) NOT NULL DEFAULT '',
        media_id BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_chat_media(media_id),
        KEY idx_chat_room(room_id,id),
        KEY idx_chat_rate(account_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS mucho_media_reports (
        media_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL,
        reason VARCHAR(32) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(media_id,account_id),
        KEY idx_media_reports_account(account_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS mucho_media_blocklist (
        sha256 CHAR(64) PRIMARY KEY,
        reason VARCHAR(128) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];
