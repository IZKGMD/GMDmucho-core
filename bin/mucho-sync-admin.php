<?php

declare(strict_types=1);

use MuchoCore\Database\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$db = (new Database())->connection();

$secretPath = '/run/secrets/admin_password';

if (!is_file($secretPath)) {
    throw new RuntimeException(
        'admin_password secret is missing: ' . $secretPath
    );
}

$password = trim((string)file_get_contents($secretPath));

if ($password === '') {
    throw new RuntimeException('admin_password secret is empty.');
}

$username = trim(
    (string)(
        getenv('ADMIN_USER')
        ?: 'admin'
    )
);

if (
    $username === '' ||
    mb_strlen($username, 'UTF-8') > 64
) {
    throw new RuntimeException('Invalid ADMIN_USER.');
}

$hash = password_hash(
    $password,
    PASSWORD_DEFAULT
);

$stmt = $db->prepare(
    'INSERT INTO admin_users
        (username,password_hash,role,is_active)
     VALUES
        (:u,:p1,:role,1)
     ON DUPLICATE KEY UPDATE
        password_hash=:p2,
        role=:role2,
        is_active=1'
);

$stmt->execute([
    'u' => $username,
    'p1' => $hash,
    'role' => 'owner',
    'p2' => $hash,
    'role2' => 'owner',
]);

echo 'Admin credentials synchronized for ' .
    $username .
    PHP_EOL;
