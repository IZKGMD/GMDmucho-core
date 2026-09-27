#!/usr/bin/env php
<?php

declare(strict_types=1);

use MuchoCore\Database\Database;
use MuchoCore\Level\LevelRevisionService;
use MuchoCore\Level\LevelValidator;
use MuchoCore\Search\LevelSearchIndexer;
use PDO;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$db = (new Database())->connection();
$revisions = new LevelRevisionService($db);
$indexer = new LevelSearchIndexer($db);
$command = $argv[1] ?? '';
$levelId = (int)($argv[2] ?? 0);

if ($levelId <= 0) {
    fwrite(STDERR, "A positive level ID is required.\n");
    exit(2);
}

if ($command === 'revisions') {
    echo json_encode(
        $revisions->list($levelId),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit(0);
}

if ($command === 'reindex') {
    if (!$indexer->upsert($levelId)) {
        fwrite(STDERR, "Reindex failed.\n");
        exit(1);
    }
    echo "LEVEL_REINDEX_OK\n";
    exit(0);
}

if ($command === 'validate') {
    $stmt = $db->prepare(
        'SELECT * FROM levels WHERE level_id=:id LIMIT 1'
    );
    $stmt->execute(['id' => $levelId]);
    $level = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$level) {
        fwrite(STDERR, "Level not found.\n");
        exit(1);
    }

    echo json_encode(
        LevelValidator::validate($level),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit(0);
}

if ($command === 'restore') {
    $revision = (int)($argv[3] ?? 0);

    if ($revision <= 0 || !in_array('--confirm', $argv, true)) {
        fwrite(
            STDERR,
            "Usage: mucho-level restore LEVEL_ID REVISION --confirm\n"
        );
        exit(2);
    }

    if (!$revisions->restore($levelId, $revision)) {
        fwrite(STDERR, "Restore failed.\n");
        exit(1);
    }

    $indexer->upsert($levelId);
    echo "LEVEL_RESTORE_OK\n";
    exit(0);
}

fwrite(
    STDERR,
    "Commands:\n" .
    "  revisions LEVEL_ID\n" .
    "  validate LEVEL_ID\n" .
    "  reindex LEVEL_ID\n" .
    "  restore LEVEL_ID REVISION --confirm\n"
);
exit(2);
