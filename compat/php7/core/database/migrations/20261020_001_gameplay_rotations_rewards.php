<?php

declare (strict_types=1);
/**
 * Core schema for gameplay features which were wired to handlers but had no
 * tracked migrations: Daily/Weekly/Event, Quests, and small/large chests.
 *
 * Safe on an existing database: CREATE IF NOT EXISTS preserves old records;
 * default quests are inserted only when creating a previously absent pool.
 * Daily/Weekly/Event rotations always require a real operator-selected level.
 */
return static function (PDO $db) {
    $existingPool = (int) $db->query("SELECT COUNT(*) FROM information_schema.tables\n         WHERE table_schema = DATABASE()\n           AND table_name = 'mucho_challenge_pool'")->fetchColumn() > 0;
    \mucho7_domain_schema_exec($db, <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_daily_rotation (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind VARCHAR(12) NOT NULL,
    level_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_daily_rotation_active (kind, enabled, starts_at, ends_at, id),
    KEY idx_daily_rotation_level (level_id, kind),
    CONSTRAINT chk_daily_rotation_times CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
);
    \mucho7_domain_schema_exec($db, <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_challenge_pool (
    challenge_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type TINYINT UNSIGNED NOT NULL,
    amount INT UNSIGNED NOT NULL,
    reward INT UNSIGNED NOT NULL,
    name VARCHAR(96) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (challenge_id),
    KEY idx_challenge_pool_active (active, challenge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
);
    \mucho7_domain_schema_exec($db, <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_reward_state (
    account_id BIGINT UNSIGNED NOT NULL,
    small_last_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
    small_count INT UNSIGNED NOT NULL DEFAULT 0,
    big_last_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
    big_count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (account_id),
    CONSTRAINT fk_mucho_reward_state_account FOREIGN KEY (account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
);
    if (!$existingPool) {
        // Genuine GD quest codes: 1=Orbs, 2=Coins, 3=Stars.
        // Start with one independent starter quest per type. No fake player
        // rewards or completions are inserted.
        \mucho7_domain_schema_exec($db, <<<'SQL'
INSERT INTO mucho_challenge_pool (type, amount, reward, name, active)
VALUES
    (1, 200, 10, 'Orb Finder', 1),
    (2, 2, 10, 'Coin Finder', 1),
    (3, 5, 10, 'Star Finder', 1)
SQL
);
    }
};
