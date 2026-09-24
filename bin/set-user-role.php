<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MuchoCore\Database\Database;

$target = $argv[1] ?? null;
$role = strtolower($argv[2] ?? '');

$pdo = (new Database())->connection();

if (!$target || $role === '') {
    echo "Usage: php bin/set-user-role.php <ACCOUNT_ID | USERNAME> <ROLE>\n";
    echo "The role must exist in the roles table.\n";
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
    echo " Unknown role '{$role}'.\n";
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
    echo " Account '{$target}' was not found or the role is already assigned.\n";
    exit(0);
}

echo " Role '{$role}' was assigned to account '{$target}'.\n";
