<?php

declare(strict_types=1);

use MuchoCore\Database\Database;
use MuchoCore\Database\Migrator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$database = new Database();

$migrator = new Migrator(
    $database->connection(),
    dirname(__DIR__) . '/database/migrations'
);

$migrator->migrate();

echo "MIGRATIONS_OK\n";
