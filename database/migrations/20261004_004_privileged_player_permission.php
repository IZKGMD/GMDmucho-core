<?php

declare(strict_types=1);

use PDO;

return static function (PDO $db): void {
    $stmt = $db->prepare(
        'SELECT id
         FROM admin_roles
         WHERE code=:code
         LIMIT 1'
    );
    $stmt->execute(['code' => 'owner']);
    $roleId = $stmt->fetchColumn();

    if ($roleId === false) {
        return;
    }

    $insert = $db->prepare(
        'INSERT IGNORE INTO admin_role_permissions
            (role_id,permission)
         VALUES
            (:role_id,:permission)'
    );
    $insert->execute([
        'role_id' => (int)$roleId,
        'permission' => 'players.privileged',
    ]);
};
