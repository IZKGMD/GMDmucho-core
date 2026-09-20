<?php

declare(strict_types=1);

/* Copyright (C) 2026 IZK */

require dirname(__DIR__) . '/vendor/autoload.php';

use MuchoCore\Database\Database;

$target = $argv[1] ?? null;
$role = strtolower(trim((string)($argv[2] ?? '')));

$legacyRoleAliases = [
    'mod' => 'moderator',
    'helper' => 'moderator',
    'elder' => 'moderator',
];

$role = $legacyRoleAliases[$role] ?? $role;

$allowedRoles = ['user', 'moderator', 'admin', 'owner'];

if (!$target || !in_array($role, $allowedRoles, true)) {
    echo "Использование: php bin/set-user-role.php <ACCOUNT_ID | USERNAME> <ROLE>\n";
    echo "Доступные роли: " . implode(', ', $allowedRoles) . "\n";
    echo "Legacy aliases: mod, helper, elder -> moderator\n";
    exit(1);
}

$pdo = (new Database())->connection();

$field = ctype_digit((string)$target) ? 'account_id' : 'username';

$findRole = $pdo->prepare(
    'SELECT id
     FROM roles
     WHERE code = :role
     LIMIT 1'
);
$findRole->execute(['role' => $role]);

$roleId = (int)$findRole->fetchColumn();

if ($roleId <= 0) {
    throw new RuntimeException("Role '{$role}' is not installed.");
}

$stmt = $pdo->prepare(
    "UPDATE accounts
     SET role_id = :role_id
     WHERE {$field} = :target"
);
$stmt->execute([
    ':role_id' => $roleId,
    ':target' => ctype_digit((string)$target)
        ? (int)$target
        : (string)$target
]);

if ($stmt->rowCount() === 0) {
    echo "Аккаунт '{$target}' не найден или роль уже установлена.\n";
    exit(0);
}

echo "Аккаунту '{$target}' успешно присвоена роль: '{$role}'\n";
