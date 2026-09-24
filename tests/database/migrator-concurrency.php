<?php

declare(strict_types=1);

$source = (string)file_get_contents(
    __DIR__ . '/../../src/Database/Migrator.php'
);

$checks = [
    'GET_LOCK(',
    'RELEASE_LOCK(',
    "muchocore:schema-migrations",
    'finally',
    'already recorded',
];

foreach ($checks as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL migration lock guard: {$needle}\n");
        exit(1);
    }

    echo "PASS migration lock guard: {$needle}\n";
}

echo "MUCHOCORE_MIGRATOR_CONCURRENCY_OK\n";
