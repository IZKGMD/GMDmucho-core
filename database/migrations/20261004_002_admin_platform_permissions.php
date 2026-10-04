<?php

declare(strict_types=1);

use PDO;

return static function (PDO $db): void {
    $roles = [
        'owner' => [
            'automation.view',
            'automation.manage',
            'backups.export',
        ],
        'admin' => [
            'automation.view',
            'automation.manage',
            'backups.export',
        ],
    ];

    $roleId = $db->prepare(
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

    foreach ($roles as $code => $permissions) {
        $roleId->execute(['code' => $code]);
        $id = $roleId->fetchColumn();

        if ($id === false) {
            continue;
        }

        foreach ($permissions as $permission) {
            $insert->execute([
                'role_id' => (int)$id,
                'permission' => $permission,
            ]);
        }
    }
};
