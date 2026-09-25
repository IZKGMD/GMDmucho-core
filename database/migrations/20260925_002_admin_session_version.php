<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = \'admin_users\'
           AND column_name = \'session_version\''
    );
    $stmt->execute();

    if ((int)$stmt->fetchColumn() === 0) {
        $db->exec(
            'ALTER TABLE admin_users
             ADD COLUMN session_version BIGINT UNSIGNED NOT NULL DEFAULT 1
             AFTER is_active'
        );
    }
};
