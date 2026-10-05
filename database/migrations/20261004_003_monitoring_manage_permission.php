<?php

declare(strict_types=1);

use PDO;

return static function (PDO $db): void {
    $permissions = [
        'owner',
        'admin',
    ];

    $findRole = $db->prepare(
        'SELECT id
         FROM admin_roles
         WHERE code=:code
         LIMIT 1'
    );

    $insert = $db->prepare(
        'INSERT IGNORE INTO admin_role_permissions
            (role_id,permission)
         VALUES
            (:role_id,:permission)'
    );

    foreach ($permissions as $code) {
        $findRole->execute(['code' => $code]);
        $roleId = $findRole->fetchColumn();

        if ($roleId === false) {
            continue;
        }

        $insert->execute([
            'role_id' => (int)$roleId,
            'permission' => 'monitoring.manage',
        ]);
    }
};
