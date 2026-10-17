<?php
declare(strict_types=1);

/** MySQL 5.7 smoke test: never run on production. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Database\Migrator;
use MuchoCore\Database\SqlCapabilities;

$host = getenv('TEST_DB_HOST');
$pass = getenv('TEST_DB_ROOT_PASSWORD');
if ($host !== '127.0.0.1' || !is_string($pass) || $pass === '') {
    throw new RuntimeException('Only isolated local CI databases are allowed.');
}
$server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$version = (string)$server->query('SELECT VERSION()')->fetchColumn();
if (!preg_match('/^5\.7\./', $version)) {
    throw new RuntimeException('Expected MySQL 5.7; got ' . $version);
}
if (SqlCapabilities::supportsWindowFunctions($server)) {
    throw new RuntimeException('MySQL 5.7 must use legacy ranking queries.');
}
$name = 'mc_compat_57_' . bin2hex(random_bytes(6));
$quotedName = chr(96) . $name . chr(96);
$server->exec('CREATE DATABASE ' . $quotedName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . $name . ';charset=utf8mb4', 'root', $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    (new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ($total < 60) {
        throw new RuntimeException('Incomplete MySQL 5.7 migrations: ' . $total);
    }
    echo "MySQL 5.7 schema migrations: {$total} OK\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $quotedName);
}
