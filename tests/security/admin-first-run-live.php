<?php
declare(strict_types=1);

// Explicit opt-in, isolated MySQL database only. Never run against a live GDPS.
if (getenv('MUCHO_ALLOW_ADMIN_SETUP_TEST') !== '1') {
    fwrite(STDERR, "Set MUCHO_ALLOW_ADMIN_SETUP_TEST=1 to run this test.\n");
    exit(2);
}

$name = (string)getenv('MUCHO_ADMIN_SETUP_TEST_DB');
if (!preg_match('/^mucho_adminfirst_test_[a-z0-9]+$/D', $name)) {
    fwrite(STDERR, "Refusing non-test database name.\n");
    exit(2);
}

$host = (string)(getenv('DB_HOST') ?: 'db');
$user = (string)(getenv('DB_USER') ?: '');
$secretPath = (string)(getenv('MUCHO_DB_PASSWORD_FILE') ?: '');
if ($user === '' || $secretPath === '' || !is_readable($secretPath)) {
    fwrite(STDERR, "Isolated test database credentials unavailable.\n");
    exit(2);
}

$password = trim((string)file_get_contents($secretPath));
$db = new PDO(
    'mysql:host='.$host.';port=3306;dbname='.$name.';charset=utf8mb4',
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
unset($password);

require dirname(__DIR__, 2).'/src/Admin/FirstRunSetup.php';

use MuchoCore\Admin\FirstRunSetup;

$db->exec("
    CREATE TABLE admin_users (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(32) NOT NULL DEFAULT 'admin',
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

function ensureAdminFirstRun(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$label);
    }
    echo 'PASS: '.$label.PHP_EOL;
}

ensureAdminFirstRun(!FirstRunSetup::prepare($db, 0), 'Fresh database has no owner marker');

$created = FirstRunSetup::createOwner($db, 'test_owner', 'Synthetic-Password-123456!');
ensureAdminFirstRun(is_array($created) && $created['role'] === 'owner', 'First owner created');
$rows = $db->query('SELECT username, password_hash, role FROM admin_users')->fetchAll(PDO::FETCH_ASSOC);
ensureAdminFirstRun(count($rows) === 1 && $rows[0]['role'] === 'owner', 'Exactly one owner persisted');
ensureAdminFirstRun(
    password_verify('Synthetic-Password-123456!', (string)$rows[0]['password_hash']),
    'Password securely hashed'
);
ensureAdminFirstRun(FirstRunSetup::prepare($db, 1), 'Owner setup permanently sealed');
ensureAdminFirstRun(
    FirstRunSetup::createOwner($db, 'second_owner', 'Synthetic-Password-123456!') === null,
    'Second owner creation rejected'
);
$db->exec('DELETE FROM admin_users');
ensureAdminFirstRun(FirstRunSetup::prepare($db, 0), 'Deleted owner does not reopen setup');
ensureAdminFirstRun(
    FirstRunSetup::createOwner($db, 'third_owner', 'Synthetic-Password-123456!') === null,
    'Previously initialized database remains sealed'
);
echo "ADMIN_FIRST_RUN_ACCEPTANCE_OK\n";
