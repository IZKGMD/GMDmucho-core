<?php
/**
 * Standalone PHP 7 schema for the experimental native shared-host runtime.
 * Runs only against a SEPARATE, EMPTY MySQL/MariaDB database.
 * Not a replacement for modern MuchoCore migrations, admin or plugin schemas.
 */
declare(strict_types=1);
require_once __DIR__ . '/features.php';

function mucho7_schema_statements()
{
    return array(
        "CREATE TABLE IF NOT EXISTS roles (
            id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(32) NOT NULL UNIQUE,
            name VARCHAR(64) NOT NULL,
            priority SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounts (
            account_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(20) NOT NULL,
            email VARCHAR(254) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            gjp2_hash VARCHAR(255) NULL,
            role_id SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_banned TINYINT(1) NOT NULL DEFAULT 0,
            last_login_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(account_id),
            UNIQUE KEY uq_accounts_username(username),
            UNIQUE KEY uq_accounts_email(email),
            KEY idx_accounts_role(role_id),
            CONSTRAINT fk_accounts_role FOREIGN KEY(role_id) REFERENCES roles(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS profiles (
            user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT UNSIGNED NOT NULL,
            stars INT UNSIGNED NOT NULL DEFAULT 0,
            moons INT UNSIGNED NOT NULL DEFAULT 0,
            diamonds INT UNSIGNED NOT NULL DEFAULT 0,
            secret_coins INT UNSIGNED NOT NULL DEFAULT 0,
            user_coins INT UNSIGNED NOT NULL DEFAULT 0,
            demons INT UNSIGNED NOT NULL DEFAULT 0,
            creator_points INT UNSIGNED NOT NULL DEFAULT 0,
            icon_id INT UNSIGNED NOT NULL DEFAULT 1,
            icon_type SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            color1 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            color2 SMALLINT UNSIGNED NOT NULL DEFAULT 3,
            glow TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(user_id), UNIQUE KEY uq_profiles_account(account_id),
            CONSTRAINT fk_profiles_account FOREIGN KEY(account_id)
                REFERENCES accounts(account_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS songs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(128) NOT NULL,
            author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            author_name VARCHAR(128) NOT NULL,
            size FLOAT NOT NULL DEFAULT 0,
            download_url VARCHAR(255) NOT NULL,
            youtube_video_id VARCHAR(32) NOT NULL DEFAULT '',
            youtube_channel_id VARCHAR(64) NOT NULL DEFAULT '',
            is_verified TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS levels (
            level_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(64) NOT NULL,
            description TEXT NULL,
            level_data LONGTEXT NOT NULL,
            level_version INT UNSIGNED NOT NULL DEFAULT 1,
            game_version INT UNSIGNED NOT NULL DEFAULT 22,
            length SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            difficulty SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            downloads INT UNSIGNED NOT NULL DEFAULT 0,
            likes INT NOT NULL DEFAULT 0,
            audio_track INT UNSIGNED NOT NULL DEFAULT 0,
            demon TINYINT NOT NULL DEFAULT 0,
            demon_difficulty SMALLINT NOT NULL DEFAULT 0,
            auto_level TINYINT NOT NULL DEFAULT 0,
            stars SMALLINT NOT NULL DEFAULT 0,
            featured INT NOT NULL DEFAULT 0,
            epic TINYINT NOT NULL DEFAULT 0,
            object_count INT UNSIGNED NOT NULL DEFAULT 0,
            original_level_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            two_player TINYINT NOT NULL DEFAULT 0,
            coins SMALLINT NOT NULL DEFAULT 0,
            coins_verified TINYINT NOT NULL DEFAULT 0,
            requested_stars SMALLINT NOT NULL DEFAULT 0,
            ldm TINYINT NOT NULL DEFAULT 0,
            song_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            extra_string TEXT NULL,
            settings_string TEXT NULL,
            copy_password VARCHAR(64) NOT NULL DEFAULT '0',
            song_ids TEXT NULL,
            sfx_ids TEXT NULL,
            wt INT NOT NULL DEFAULT 0,
            wt2 INT NOT NULL DEFAULT 0,
            ts BIGINT NOT NULL DEFAULT 0,
            unlisted2 TINYINT NOT NULL DEFAULT 0,
            is_deleted TINYINT NOT NULL DEFAULT 0,
            is_unlisted TINYINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(level_id), KEY idx_levels_account(account_id),
            KEY idx_levels_public(is_deleted,is_unlisted,created_at),
            CONSTRAINT fk_levels_account FOREIGN KEY(account_id)
                REFERENCES accounts(account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS php7_auth_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action VARCHAR(20) NOT NULL,
            ip_hash CHAR(64) NOT NULL,
            username_hash CHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id), KEY idx_auth_ip(action,ip_hash,created_at),
            KEY idx_auth_name(action,username_hash,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function mucho7_create_schema(PDO $pdo)
{
    // DDL commits implicitly in MariaDB. Never touch an existing installation;
    // require empty disposable schema to avoid partial/incompatible migrations.
    $tableNames = array('roles', 'accounts', 'profiles', 'songs', 'levels',
        'php7_auth_events', 'php7_comments', 'php7_votes', 'php7_cloud_saves');
    $marks = implode(',', array_fill(0, count($tableNames), '?'));
    $count = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name IN (' . $marks . ')'
    );
    $count->execute($tableNames);
    if ((int)$count->fetchColumn() !== 0) {
        throw new RuntimeException('Expected an empty test database; existing tables were not modified.');
    }
    foreach (mucho7_schema_statements() as $sql) {
        $pdo->exec($sql);
    }
    mucho7_upgrade_features($pdo);
    $pdo->exec(
        "INSERT INTO roles (code,name,priority) VALUES
        ('user','User',0),('moderator','Moderator',50),
        ('elder_moderator','Elder Moderator',75),('owner','Owner',100)"
    );
}
