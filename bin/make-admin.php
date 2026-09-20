<?php
declare(strict_types=1);

use MuchoCore\Database\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$username = trim((string)($argv[1] ?? 'Admin'));
$email = trim((string)($argv[2] ?? 'admin@localhost'));
$password = getenv('MUCHO_ADMIN_PASSWORD');

if ($username === '' || $email === '' || $password === false || $password === '') {
    fwrite(STDERR, "Usage: MUCHO_ADMIN_PASSWORD='strong-password' php bin/make-admin.php [username] [email]\n");
    exit(1);
}

$pdo = (new Database())->connection();
$hash = password_hash($password, PASSWORD_DEFAULT);

$roleQuery = $pdo->prepare(
    'SELECT id
     FROM roles
     WHERE code = :role
     LIMIT 1'
);
$roleQuery->execute(['role' => 'owner']);
$ownerRoleId = (int)$roleQuery->fetchColumn();

if ($ownerRoleId <= 0) {
    throw new RuntimeException('Owner role is not installed.');
}

$stmt = $pdo->prepare(
    'SELECT account_id
     FROM accounts
     WHERE username = :username
     LIMIT 1'
);
$stmt->execute(['username' => $username]);
$accountId = $stmt->fetchColumn();

if ($accountId !== false) {
    $update = $pdo->prepare(
        'UPDATE accounts
         SET role_id = :role_id,
             gjp2_hash = :gjp2,
             is_active = 1,
             is_banned = 0,
             email = :email
         WHERE account_id = :account_id'
    );
    $update->execute([
        'role_id' => $ownerRoleId,
        'gjp2' => $hash,
        'email' => $email,
        'account_id' => (int)$accountId,
    ]);
} else {
    $insert = $pdo->prepare(
        'INSERT INTO accounts (
            username,
            password_hash,
            gjp2_hash,
            email,
            role_id,
            is_active
         )
         VALUES (
            :username,
            :password_hash,
            :gjp2_hash,
            :email,
            :role_id,
            1
         )'
    );
    $insert->execute([
        'username' => $username,
        'password_hash' => $hash,
        'gjp2_hash' => $hash,
        'email' => $email,
        'role_id' => $ownerRoleId,
    ]);
    $accountId = (int)$pdo->lastInsertId();

    $profile = $pdo->prepare(
        'INSERT INTO profiles (account_id)
         VALUES (:account_id)'
    );
    $profile->execute([
        'account_id' => $accountId,
    ]);
}

echo "Owner account ready: {$username}\n";
