<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Search\LevelSearchIndexer;

if (getenv('TEST_DB_HOST') !== '127.0.0.1'
    || !is_string(getenv('TEST_DB_ROOT_PASSWORD'))
    || getenv('TEST_DB_ROOT_PASSWORD') === '') {
    throw new RuntimeException('An isolated CI MariaDB instance is required.');
}

$host = '127.0.0.1';
$port = getenv('TEST_DB_PORT') ?: '3306';
$pass = (string)getenv('TEST_DB_ROOT_PASSWORD');
$settings = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$root = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    'root', $pass, $settings
);
$dbName = 'mc_index_test_' . bin2hex(random_bytes(5));
$root->exec('CREATE DATABASE ' . $dbName . ' CHARACTER SET utf8mb4');

try {
    $db = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
        'root', $pass, $settings
    );
    $db->exec(
        'CREATE TABLE accounts (
            account_id BIGINT UNSIGNED PRIMARY KEY,
            username VARCHAR(30) NOT NULL
        ) ENGINE=InnoDB'
    );
    $db->exec(
        'CREATE TABLE levels (
            level_id BIGINT UNSIGNED PRIMARY KEY,
            account_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(128) NOT NULL,
            game_version INT NOT NULL,
            is_deleted TINYINT NOT NULL DEFAULT 0,
            is_unlisted TINYINT NOT NULL DEFAULT 0,
            KEY idx_account_level (account_id, level_id)
        ) ENGINE=InnoDB'
    );
    $db->exec("INSERT INTO accounts VALUES (2, 'Creator'),(3, 'Another')");
    $add = $db->prepare(
        'INSERT INTO levels(level_id, account_id, name, game_version)
         VALUES(:id, :owner, :name, 22)'
    );
    for ($id = 1; $id <= 700; $id++) {
        $add->execute([
            'id' => $id,
            'owner' => $id <= 600 ? 2 : 3,
            'name' => 'Test ' . $id,
        ]);
    }

    $migration = require dirname(__DIR__, 2)
        . '/database/migrations/20260928_001_intelligence_scale.php';
    $migration->up($db);
    // Migration populates its search table once. Clear it to exercise
    // the rebuild path rather than merely checking preseeded rows.
    $db->exec('DELETE FROM mucho_level_search_index');

    $indexer = new LevelSearchIndexer($db);
    $assert = static function (bool $condition, string $name): void {
        if (!$condition) {
            throw new RuntimeException('FAIL ' . $name);
        }
        echo 'PASS ' . $name . PHP_EOL;
    };

    $count = static fn(): int => (int)$db->query(
        'SELECT COUNT(*) FROM mucho_level_search_index'
    )->fetchColumn();

    $assert(
        $indexer->rebuild(300) === 300 && $count() === 300,
        'limited rebuild spans multiple bounded batches'
    );
    $assert(
        (int)$db->query(
            'SELECT MAX(level_id) FROM mucho_level_search_index'
        )->fetchColumn() === 300,
        'limited rebuild retains ascending level ordering'
    );
    $assert(
        $indexer->rebuildAccount(3) === 100 && $count() === 400,
        'account rebuild includes only the selected creator'
    );
    $assert(
        $indexer->rebuild() === 700 && $count() === 700,
        'full rebuild processes all levels across multiple batches'
    );
    $db->exec("UPDATE levels SET name='Renamed Creator Level' WHERE level_id=700");
    $assert(
        $indexer->rebuildAccount(3) === 100
        && $db->query(
            'SELECT normalized_name FROM mucho_level_search_index
             WHERE level_id=700'
        )->fetchColumn() === 'renamed creator level',
        'account rebuild refreshes existing index entries'
    );
    $assert(
        $indexer->rebuildAccount(999) === 0,
        'nonexistent account returns zero'
    );
    echo "MUCHOCORE_SEARCH_INDEX_BATCH_OK\n";
} finally {
    $root->exec('DROP DATABASE IF EXISTS ' . $dbName);
}
