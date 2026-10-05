<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$database = file_get_contents($root . '/src/Database/Database.php');

if (!is_string($database)) {
    throw new RuntimeException('Unable to read src/Database/Database.php');
}

foreach ([
    'public function connection(): PDO',
    'SET time_zone = \' +00:00\'',
    'Environment::get(\'DB_HOST\'',
    'Environment::get(\'DB_PASS\'',
] as $needle) {
    if (!str_contains($database, $needle)) {
        throw new RuntimeException('Database connection contract missing: ' . $needle);
    }
}

if (str_contains($database, 'shared hosting')) {
    throw new RuntimeException('Database connection still contains removed shared-hosting documentation.');
}

echo "database-connection-contract: OK\n";
