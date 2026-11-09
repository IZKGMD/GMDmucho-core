<?php

declare(strict_types=1);

use MuchoCore\Database\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$db = (new Database())->connection();

// Normal deploys/updates must not silently provision or overwrite the
// credentials chosen by an administrator in the first-run browser wizard.
// Reset remains an explicit server-console operation.
$resetRequested = in_array('--reset-password', $argv ?? [], true);

if (!$resetRequested) {
    $count = (int)$db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    echo $count === 0
        ? "First administrator not created yet; visit /admin/ to register the owner.\n"
        : "Administrator credentials preserved (no password reset requested).\n";
    exit(0);
}

$secretPath = '/run/secrets/admin_password';
if (!is_file($secretPath)) {
    throw new RuntimeException('admin_password secret is missing: ' . $secretPath);
}

$password = trim((string)file_get_contents($secretPath));
if (strlen($password) < 10) {
    throw new RuntimeException('admin_password secret must have at least 10 characters.');
}

$username = trim((string)(getenv('ADMIN_USER') ?: 'admin'));
if (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,31}$/D', $username)) {
    throw new RuntimeException('Invalid ADMIN_USER.');
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $db->prepare(
    "INSERT INTO admin_users (username,password_hash,role,is_active)
     VALUES (:u,:p1,'owner',1)
     ON DUPLICATE KEY UPDATE
         password_hash=:p2,
         role='owner',
         is_active=1"
);
$stmt->execute([
    'u' => $username,
    'p1' => $hash,
    'p2' => $hash,
]);

// Explicit console resets also complete the first-run gate. An attacker
// must never be allowed to claim a second owner after CLI recovery.
$db->exec('
    CREATE TABLE IF NOT EXISTS admin_initialization (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');
$db->exec('INSERT IGNORE INTO admin_initialization (id) VALUES (1)');

echo 'Administrator password reset explicitly for ' . $username . PHP_EOL;
