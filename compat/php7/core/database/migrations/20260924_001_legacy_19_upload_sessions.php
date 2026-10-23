<?php

declare (strict_types=1);
/*
 * Legacy Geometry Dash 1.9 upload sessions.
 *
 * Some 1.9 clients authenticate successfully but omit gjp on subsequent
 * level-upload requests. The session is bound to account, device UDID and
 * source IP, expires after one hour, and stores only a password hash.
 */
return static function (PDO $db) {
    $db->exec("\n        CREATE TABLE IF NOT EXISTS mucho_legacy_19_sessions (\n            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n\n            account_id BIGINT UNSIGNED NOT NULL,\n\n            udid_hash VARCHAR(255) NOT NULL,\n\n            ip_address VARCHAR(45) NOT NULL,\n\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            last_used_at TIMESTAMP NULL DEFAULT NULL,\n\n            expires_at DATETIME NOT NULL,\n\n            KEY idx_ml19_account_ip (account_id, ip_address),\n            KEY idx_ml19_expiry (expires_at),\n\n            CONSTRAINT fk_ml19_account\n                FOREIGN KEY (account_id)\n                REFERENCES accounts(account_id)\n                ON DELETE CASCADE\n        ) ENGINE=InnoDB\n        DEFAULT CHARSET=utf8mb4\n        COLLATE=utf8mb4_unicode_ci\n    ");
};
