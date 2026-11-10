<?php

declare (strict_types=1);

return static function (PDO $db) {
    \mucho7_domain_schema_exec($db, <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_account_user_info (
    account_id BIGINT UNSIGNED NOT NULL,
    levels_info MEDIUMBLOB NOT NULL,
    payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id),
    KEY idx_maui_updated (updated_at),
    CONSTRAINT fk_maui_account
        FOREIGN KEY (account_id)
        REFERENCES accounts(account_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
};
