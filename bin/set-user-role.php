<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MuchoCore\Database\Database;

$target = $argv[1] ?? null;
$role = strtolower($argv[2] ?? '');

$pdo = (new Database())->connection();

if (!$target || $role === '') {
    echo "Использование: php bin/set-user-role.php <ACCOUNT_ID | USERNAME> <ROLE>\n";
    echo "Роль должна существовать в таблице roles.\n";
    exit(1);
}

$roleStmt = $pdo->prepare(
    'SELECT id
     FROM roles
     WHERE code=:role
     LIMIT 1'
);

$roleStmt->execute([
    ':role' => $role
]);

$roleId = $roleStmt->fetchColumn();

if ($roleId === false) {
    echo " Неизвестная роль '{$role}'.\n";
    exit(1);
}

$field = is_numeric($target) ? 'account_id' : 'username';

$stmt = $pdo->prepare(
    "UPDATE accounts
     SET role_id=:role_id
     WHERE {$field}=:target"
);

$stmt->execute([
    ':role_id' => (int)$roleId,
    ':target' => is_numeric($target) ? (int)$target : $target
]);

if ($stmt->rowCount() === 0) {
    echo " Аккаунт '{$target}' не найден или роль уже установлена.\n";
    exit(0);
}

echo " Аккаунту '{$target}' успешно присвоена роль: '{$role}'\n";
