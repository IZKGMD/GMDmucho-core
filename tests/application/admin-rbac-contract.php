<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Admin/AdminRbac.php';

use MuchoCore\Admin\AdminRbac;

function assertValue(mixed $expected, mixed $actual, string $name): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            "FAIL {$name}: expected " .
            var_export($expected, true) .
            ', got ' .
            var_export($actual, true) .
            PHP_EOL
        );
        exit(1);
    }

    echo "PASS {$name}" . PHP_EOL;
}

assertValue(true, AdminRbac::isBuiltInRole('owner'), 'owner is built-in');
assertValue(true, AdminRbac::isBuiltInRole('moderator'), 'moderator is built-in');
assertValue(false, AdminRbac::isBuiltInRole('level-moderator'), 'custom role is not built-in');

assertValue(
    'players.manage',
    AdminRbac::normalizePermission('users.manage'),
    'legacy permission alias'
);

$permissions=AdminRbac::permissions();
assertValue(true, isset($permissions['roles.manage']), 'roles.manage exists');
assertValue(true, isset($permissions['self.security']), 'self.security exists');

assertValue(
    'levels.rate',
    AdminRbac::permissionForContext('level-rate-save',null,20),
    'level rating action permission'
);

assertValue(
    'music.manage',
    AdminRbac::permissionForContext('music-upload',null,30),
    'music action permission'
);

assertValue(
    'admins.manage',
    AdminRbac::permissionForContext('admin-create',null,40),
    'admin management action permission'
);

assertValue(
    'comments.manage',
    AdminRbac::permissionForContext(null,'comments',0),
    'comments page permission'
);

assertValue(
    'roles.manage',
    AdminRbac::permissionForContext(null,'roles',0),
    'roles page permission'
);

$normalized=AdminRbac::normalizePermissionList([
    'users.manage',
    'players.manage',
    'roles.manage',
    'does.not.exist',
    'roles.manage',
]);

assertValue(
    ['players.manage','roles.manage'],
    $normalized,
    'permission normalization'
);

echo "MUCHOCORE_ADMIN_RBAC_OK" . PHP_EOL;
