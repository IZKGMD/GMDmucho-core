<?php

declare (strict_types=1);
/*
 * Cvolton-compatible post-login authentication grants.
 *
 * Cvolton's sessionGrants mode remembers a successful account login for one
 * hour, bound to account ID and client IP, so subsequent legacy endpoints can
 * authenticate without requiring the client to resend a valid GJP every time.
 */
return static function (PDO $db) {
    \mucho7_domain_schema_exec($db, "\n        CREATE TABLE IF NOT EXISTS mucho_auth_sessions (\n            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n\n            account_id BIGINT UNSIGNED NOT NULL,\n\n            ip_address VARCHAR(45) NOT NULL,\n\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n\n            last_used_at TIMESTAMP NULL DEFAULT NULL,\n\n            expires_at DATETIME NOT NULL,\n\n            UNIQUE KEY uq_mucho_auth_session (account_id, ip_address),\n            KEY idx_mucho_auth_session_expiry (expires_at),\n\n            CONSTRAINT fk_mucho_auth_session_account\n                FOREIGN KEY (account_id)\n                REFERENCES accounts(account_id)\n                ON DELETE CASCADE\n        ) ENGINE=InnoDB\n        DEFAULT CHARSET=utf8mb4\n        COLLATE=utf8mb4_unicode_ci\n    ");
};
