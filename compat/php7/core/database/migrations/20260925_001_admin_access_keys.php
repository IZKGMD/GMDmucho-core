<?php

declare (strict_types=1);
/*
 * MuchoCore administrator access-key credentials.
 *
 * Access keys are password-equivalent credentials intended for fast admin
 * sign-in. Only a one-way hash is persisted; the raw key is shown once when
 * generated. TOTP, when enabled, is still required.
 */
return static function (PDO $db) {
    $exists = (int) $db->query("SELECT COUNT(*)\n         FROM information_schema.tables\n         WHERE table_schema=DATABASE()\n           AND table_name='admin_users'")->fetchColumn();
    if ($exists === 0) {
        return;
    }
    $columns = $db->query('SHOW COLUMNS FROM admin_users')->fetchAll(PDO::FETCH_ASSOC);
    $hasHash = false;
    $hasCreated = false;
    foreach ($columns as $column) {
        $name = (string) ($column['Field'] ?? '');
        if ($name === 'access_key_hash') {
            $hasHash = true;
        }
        if ($name === 'access_key_created_at') {
            $hasCreated = true;
        }
    }
    if (!$hasHash) {
        \mucho7_domain_schema_exec($db, "ALTER TABLE admin_users\n             ADD COLUMN access_key_hash VARCHAR(255) NULL AFTER totp_secret");
    }
    if (!$hasCreated) {
        \mucho7_domain_schema_exec($db, "ALTER TABLE admin_users\n             ADD COLUMN access_key_created_at TIMESTAMP NULL\n             AFTER access_key_hash");
    }
};
