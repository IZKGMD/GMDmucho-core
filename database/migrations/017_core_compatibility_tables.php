<?php

declare(strict_types=1);

/*
 * MuchoCore core compatibility schema
 * Copyright (C) 2026 IZK
 *
 * This migration makes fresh installs self-contained: every table used by
 * the main GD endpoint surface and the bundled control/API modules exists
 * immediately after migration.
 */

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS friends (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    friend_account_id BIGINT UNSIGNED NOT NULL,
    is_new TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_friends_pair (account_id, friend_account_id),
    KEY idx_friends_account (account_id),
    KEY idx_friends_friend (friend_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    blocked_account_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_blocks_pair (account_id, blocked_account_id),
    KEY idx_blocks_account (account_id),
    KEY idx_blocks_blocked (blocked_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS friend_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    to_account_id BIGINT UNSIGNED NOT NULL,
    comment VARCHAR(1000) NOT NULL DEFAULT '',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_friend_requests_from (account_id),
    KEY idx_friend_requests_to (to_account_id),
    KEY idx_friend_requests_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    to_account_id BIGINT UNSIGNED NOT NULL,
    subject TEXT NOT NULL,
    body LONGTEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    is_sender_deleted TINYINT(1) NOT NULL DEFAULT 0,
    is_receiver_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_messages_from (account_id, is_sender_deleted),
    KEY idx_messages_to (to_account_id, is_receiver_deleted),
    KEY idx_messages_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_cloud_saves (
    account_id BIGINT UNSIGNED NOT NULL,
    ciphertext LONGBLOB NOT NULL,
    nonce VARBINARY(12) NOT NULL,
    auth_tag VARBINARY(16) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    backed_up_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (account_id),
    KEY idx_cloud_saves_backed_up (backed_up_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_cloud_save_revisions (
    history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    ciphertext LONGBLOB NOT NULL,
    nonce VARBINARY(12) NOT NULL,
    auth_tag VARBINARY(16) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    original_backed_up_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_id),
    UNIQUE KEY uq_cloud_revision (account_id, revision),
    KEY idx_cloud_revision_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_reward_state (
    account_id BIGINT UNSIGNED NOT NULL,
    small_last_at BIGINT NOT NULL DEFAULT 0,
    small_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    big_last_at BIGINT NOT NULL DEFAULT 0,
    big_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_challenge_pool (
    challenge_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type INT NOT NULL DEFAULT 0,
    amount INT UNSIGNED NOT NULL DEFAULT 0,
    reward INT UNSIGNED NOT NULL DEFAULT 0,
    name VARCHAR(255) NOT NULL DEFAULT '',
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (challenge_id),
    KEY idx_challenge_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_level_scores (
    score_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    level_id BIGINT UNSIGNED NOT NULL,
    is_daily TINYINT(1) NOT NULL DEFAULT 0,
    daily_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    coins TINYINT UNSIGNED NOT NULL DEFAULT 0,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    play_time INT UNSIGNED NOT NULL DEFAULT 0,
    progresses LONGTEXT NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    updated_at BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (score_id),
    UNIQUE KEY uq_level_score (account_id, level_id, is_daily),
    KEY idx_level_scores_level (level_id),
    KEY idx_level_scores_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_platformer_scores (
    score_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    level_id BIGINT UNSIGNED NOT NULL,
    time_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
    points BIGINT NOT NULL DEFAULT 0,
    created_at BIGINT NOT NULL DEFAULT 0,
    updated_at BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (score_id),
    UNIQUE KEY uq_platformer_score (account_id, level_id),
    KEY idx_platformer_level (level_id),
    KEY idx_platformer_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_daily_rotation (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    level_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(16) NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_daily_active (kind, enabled, starts_at, ends_at),
    KEY idx_daily_level (level_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_gauntlets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    level1 BIGINT UNSIGNED NOT NULL DEFAULT 0,
    level2 BIGINT UNSIGNED NOT NULL DEFAULT 0,
    level3 BIGINT UNSIGNED NOT NULL DEFAULT 0,
    level4 BIGINT UNSIGNED NOT NULL DEFAULT 0,
    level5 BIGINT UNSIGNED NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_gauntlets_enabled (enabled, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_map_packs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    levels VARCHAR(1024) NOT NULL DEFAULT '',
    stars INT NOT NULL DEFAULT 0,
    coins INT NOT NULL DEFAULT 0,
    difficulty INT NOT NULL DEFAULT 0,
    color1 INT NOT NULL DEFAULT 0,
    color2 INT NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_map_packs_enabled (enabled, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_level_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    level_id BIGINT UNSIGNED NOT NULL,
    reporter_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_level_reporter (level_id, reporter_hash),
    KEY idx_level_reports_level (level_id),
    KEY idx_level_reports_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_profile_presence (
    account_id BIGINT UNSIGNED NOT NULL,
    client_token_hash CHAR(64) NOT NULL DEFAULT '',
    last_seen TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (account_id),
    KEY idx_presence_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_account_roles (
    account_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'PLAYER',
    verified TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_music_rate_limits (
    ip VARCHAR(45) NOT NULL,
    last_upload_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'admin',
    totp_secret VARCHAR(64) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id BIGINT UNSIGNED NULL,
    username VARCHAR(64) NOT NULL,
    action VARCHAR(128) NOT NULL,
    target VARCHAR(255) NULL,
    metadata TEXT NULL,
    ip VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_audit_time (created_at),
    KEY idx_admin_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_profile_customization (
    account_id BIGINT UNSIGNED NOT NULL,
    display_name VARCHAR(32) NOT NULL DEFAULT '',
    status VARCHAR(48) NOT NULL DEFAULT '',
    bio VARCHAR(240) NOT NULL DEFAULT '',
    theme_primary VARCHAR(7) NOT NULL DEFAULT '#42D9CF',
    theme_secondary VARCHAR(7) NOT NULL DEFAULT '#806EFF',
    banner VARCHAR(64) NOT NULL DEFAULT 'gradient_01',
    title VARCHAR(48) NOT NULL DEFAULT '',
    badges TEXT NOT NULL,
    pinned_levels VARCHAR(96) NOT NULL DEFAULT '',
    showcase VARCHAR(200) NOT NULL DEFAULT 'stars,demons,creator_points',
    favorite_difficulty VARCHAR(32) NOT NULL DEFAULT '',
    online_visible TINYINT(1) NOT NULL DEFAULT 1,
    edit_token_hash VARCHAR(64) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_feature_flags (
    flag_key VARCHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    description VARCHAR(255) NOT NULL DEFAULT '',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (flag_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_client_releases (
    platform VARCHAR(32) NOT NULL,
    current_version VARCHAR(32) NULL,
    minimum_version VARCHAR(32) NULL,
    download_url VARCHAR(500) NULL,
    sha256 VARCHAR(64) NULL,
    release_notes TEXT NULL,
    signer_sha256 VARCHAR(64) NULL,
    maintenance TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (platform)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_admin_client_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    client_version VARCHAR(32) NOT NULL DEFAULT '',
    created_ip_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_client_token (token_hash),
    KEY idx_admin_client_expiry (expires_at),
    KEY idx_admin_client_admin (admin_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_admin_client_login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_login_ip (ip_hash, created_at),
    KEY idx_admin_login_user (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_admin_client_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    admin_username VARCHAR(64) NOT NULL,
    action VARCHAR(128) NOT NULL,
    target_type VARCHAR(64) NOT NULL DEFAULT '',
    target_id VARCHAR(128) NOT NULL DEFAULT '',
    details TEXT NULL,
    ip_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_client_audit_time (created_at),
    KEY idx_admin_client_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_client_release_uploads (
    upload_id VARCHAR(64) NOT NULL,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    platform VARCHAR(32) NOT NULL,
    version VARCHAR(32) NOT NULL,
    minimum_version VARCHAR(32) NOT NULL,
    release_notes TEXT NULL,
    original_name VARCHAR(255) NOT NULL,
    temp_name VARCHAR(255) NOT NULL,
    total_size BIGINT UNSIGNED NOT NULL,
    received_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (upload_id),
    KEY idx_release_upload_admin (admin_user_id),
    KEY idx_release_upload_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_client_release_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    platform VARCHAR(32) NOT NULL,
    version VARCHAR(32) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    signer_sha256 CHAR(64) NULL,
    signature_status VARCHAR(32) NOT NULL DEFAULT 'unchecked',
    download_url VARCHAR(500) NOT NULL,
    uploaded_by VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_release_platform (platform),
    KEY idx_release_version (version),
    KEY idx_release_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS gdps_settings (
    setting_key VARCHAR(80) NOT NULL,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS admin_deleted_accounts_v4 (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    username VARCHAR(64) NULL,
    snapshot LONGTEXT NOT NULL,
    deleted_by VARCHAR(64) NOT NULL,
    deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_deleted_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
