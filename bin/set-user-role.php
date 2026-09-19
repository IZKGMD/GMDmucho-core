<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MuchoCore\Database\Database;

$target = $argv[1] ?? null;
$role = strtolower($argv[2] ?? '');

$allowedRoles = ['user', 'moderator', 'elder', 'admin', 'owner'];

if (!$target || !in_array($role, $allowedRoles, true)) {
    echo "Использование: php bin/set-user-role.php <ACCOUNT_ID | USERNAME> <ROLE>\n";
    echo "Доступные роли: " . implode(', ', $allowedRoles) . "\n";
    exit(1);
}

$pdo = (new Database())->connection();

$field = is_numeric($target) ? 'account_id' : 'username';
$stmt = $pdo->prepare("UPDATE accounts SET role = :role WHERE {$field} = :target");
$stmt->execute([
    ':role' => $role,
    ':target' => is_numeric($target) ? (int)$target : $target
]);

if ($stmt->rowCount() === 0) {
    echo " Аккаунт '{$target}' не найден или роль уже установлена.\n";
    exit(0);
}

echo " Аккаунту '{$target}' успешно присвоена роль: '{$role}'\n";
