<?php

declare (strict_types=1);
/*
 * Native administrator passkeys (WebAuthn / FIDO2).
 *
 * Private keys never reach the server. MuchoCore stores only the credential
 * identifier, public key, opaque user handle and verification metadata.
 */
return static function (PDO $db) {
    $columns = $db->query('SHOW COLUMNS FROM admin_users')->fetchAll(PDO::FETCH_ASSOC);
    $hasHandle = false;
    foreach ($columns as $column) {
        if ((string) ($column['Field'] ?? '') === 'passkey_user_handle') {
            $hasHandle = true;
            break;
        }
    }
    if (!$hasHandle) {
        \mucho7_domain_schema_exec($db, "ALTER TABLE admin_users\n             ADD COLUMN passkey_user_handle VARBINARY(64) NULL\n             AFTER access_key_created_at");
    }
    \mucho7_domain_schema_exec($db, "CREATE TABLE IF NOT EXISTS admin_passkeys (\n            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n            admin_user_id BIGINT UNSIGNED NOT NULL,\n            credential_id VARCHAR(1400) NOT NULL,\n            user_handle VARBINARY(64) NOT NULL,\n            credential_public_key TEXT NOT NULL,\n            signature_counter BIGINT UNSIGNED NOT NULL DEFAULT 0,\n            aaguid VARCHAR(64) NULL,\n            label VARCHAR(120) NOT NULL DEFAULT 'Admin Passkey',\n            is_backup_eligible TINYINT(1) NULL,\n            is_backed_up TINYINT(1) NULL,\n            is_active TINYINT(1) NOT NULL DEFAULT 1,\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            last_used_at TIMESTAMP NULL,\n            UNIQUE KEY uq_admin_passkey_credential (credential_id),\n            KEY idx_admin_passkeys_user (admin_user_id,is_active),\n            CONSTRAINT fk_admin_passkeys_admin\n                FOREIGN KEY (admin_user_id) REFERENCES admin_users(id)\n                ON DELETE CASCADE\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
