<?php

declare(strict_types=1);

// Preserve known dates. Unknown historical dates stay NULL rather than
// falsely claiming that an existing friendship started during this upgrade.
return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS friends (
    account_id BIGINT UNSIGNED NOT NULL,
    friend_account_id BIGINT UNSIGNED NOT NULL,
    is_new TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (account_id, friend_account_id),
    KEY idx_friends_peer (friend_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    if ($db->query("SHOW COLUMNS FROM friends LIKE 'created_at'")->fetch() === false) {
        $db->exec('ALTER TABLE friends ADD COLUMN created_at TIMESTAMP NULL DEFAULT NULL');
    }
};
