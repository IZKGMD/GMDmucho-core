#!/usr/bin/env php
<?php

declare(strict_types=1);

use MuchoCore\Database\Database;
use MuchoCore\Search\LevelSearchIndexer;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$db = (new Database())->connection();
$indexer = new LevelSearchIndexer($db);
$command = $argv[1] ?? '';

if ($command === 'rebuild') {
    $limit = null;

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--limit=')) {
            $limit = max(1, (int)substr($arg, 8));
        }
    }

    $count = $indexer->rebuild($limit);
    echo "SEARCH_INDEXED={$count}\n";
    exit(0);
}

if ($command === 'search') {
    $query = trim((string)($argv[2] ?? ''));
    $limit = max(1, min(50, (int)($argv[3] ?? 20)));

    if ($query === '') {
        fwrite(STDERR, "Usage: mucho-search search QUERY [LIMIT]\n");
        exit(2);
    }

    echo json_encode(
        $indexer->searchIds($query, $limit),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit(0);
}

fwrite(
    STDERR,
    "Commands:\n" .
    "  rebuild [--limit=N]\n" .
    "  search QUERY [LIMIT]\n"
);
exit(2);
