<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_quest_accounts (
    account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    xp BIGINT UNSIGNED NOT NULL DEFAULT 0,
    claims_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    stars_high BIGINT UNSIGNED NOT NULL DEFAULT 0,
    moons_high BIGINT UNSIGNED NOT NULL DEFAULT 0,
    demons_high BIGINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_quest_account FOREIGN KEY (account_id) REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_daily_quests (
    account_id BIGINT UNSIGNED NOT NULL,
    quest_day DATE NOT NULL,
    metric ENUM('stars','moons','demons') NOT NULL,
    target INT UNSIGNED NOT NULL,
    reward_xp INT UNSIGNED NOT NULL,
    progress BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_score BIGINT UNSIGNED NOT NULL,
    claimed_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (account_id,quest_day,metric),
    KEY idx_daily_quest_history (quest_day,account_id),
    CONSTRAINT fk_daily_quest_account FOREIGN KEY (account_id) REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
