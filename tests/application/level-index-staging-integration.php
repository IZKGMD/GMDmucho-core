<?php

declare(strict_types=1);

/**
 * Ephemeral MariaDB integration for candidate level-list indexes.
 * NEVER connects to a production host and never touches a real GDPS database.
 */
if (getenv('TEST_DB_HOST') !== '127.0.0.1'
    || !is_string(getenv('TEST_DB_ROOT_PASSWORD'))
    || getenv('TEST_DB_ROOT_PASSWORD') === '') {
    throw new RuntimeException('Requires isolated CI MariaDB on 127.0.0.1.');
}

$port = getenv('TEST_DB_PORT') ?: '3306';
$password = (string)getenv('TEST_DB_ROOT_PASSWORD');
$opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$root = new PDO(
    "mysql:host=127.0.0.1;port={$port};charset=utf8mb4",
    'root', $password, $opts
);

$name = 'mc_level_idx_' . bin2hex(random_bytes(5));
$root->exec("CREATE DATABASE {$name} CHARACTER SET utf8mb4");
try {
    $db = new PDO(
        "mysql:host=127.0.0.1;port={$port};dbname={$name};charset=utf8mb4",
        'root', $password, $opts
    );
    $db->exec(<<<'SQL'
CREATE TABLE levels (
    level_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    is_deleted TINYINT NOT NULL DEFAULT 0,
    is_unlisted TINYINT NOT NULL DEFAULT 0,
    likes INT NOT NULL DEFAULT 0,
    downloads INT NOT NULL DEFAULT 0,
    featured INT NOT NULL DEFAULT 0,
    game_version INT NOT NULL DEFAULT 22,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_levels_created (created_at)
) ENGINE=InnoDB
SQL);

    // 10k deterministic representative rows. Batched INSERT avoids 10k
    // driver round trips while retaining real InnoDB B-tree behavior.
    for ($base = 1; $base <= 10000; $base += 500) {
        $rows = [];
        for ($id = $base; $id < $base + 500; $id++) {
            $rows[] = '(' . implode(',', [
                $id,
                ($id % 17 === 0 ? 1 : 0),
                ($id % 29 === 0 ? 1 : 0),
                $id * 13 % 2999,
                $id * 7 % 5003,
                ($id % 5 === 0 ? 1 : 0),
                22,
                'FROM_UNIXTIME(' . (1760000000 + $id * 45) . ')',
                'FROM_UNIXTIME(' . (1760000000 + $id * 61) . ')',
            ]) . ')';
        }
        $db->exec(
            'INSERT INTO levels
            (level_id,is_deleted,is_unlisted,likes,downloads,featured,
             game_version,created_at,updated_at)
            VALUES ' . implode(',', $rows)
        );
    }

    $queries = [
        'popular' => 'SELECT level_id FROM levels
            WHERE is_deleted=0 AND is_unlisted=0 AND game_version<=22
            ORDER BY likes DESC, level_id DESC LIMIT 10',
        'downloaded' => 'SELECT level_id FROM levels
            WHERE is_deleted=0 AND is_unlisted=0 AND game_version<=22
            ORDER BY downloads DESC, level_id DESC LIMIT 10',
        'featured' => 'SELECT level_id FROM levels
            WHERE is_deleted=0 AND is_unlisted=0 AND featured>0 AND game_version<=22
            ORDER BY updated_at DESC, level_id DESC LIMIT 10',
    ];

    $before = [];
    foreach ($queries as $key => $sql) {
        $before[$key] = $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    $sqlPath = dirname(__DIR__, 2)
        . '/tools/performance/optional_level_indexes_staging.sql';
    $script = (string)file_get_contents($sqlPath);
    // Strip full-line comments. SQL file consists only of three simple
    // ALTER statements. The guard prevents unexpected destructive SQL.
    $script = preg_replace('/^\s*--.*$/m', '', $script) ?? '';
    $statements = array_values(array_filter(array_map(
        'trim', explode(';', $script)
    )));
    if (count($statements) !== 3) {
        throw new RuntimeException('Unexpected number of staging ALTER statements');
    }

    foreach ($statements as $statement) {
        if (!preg_match('/^ALTER TABLE levels\s+ADD INDEX idx_mc_visible_/i', $statement)) {
            throw new RuntimeException('Staging index script contains a non-allowed query');
        }
        $db->exec($statement);
    }

    $indexes = [
        'popular' => ['idx_mc_visible_likes', 'likes'],
        'downloaded' => ['idx_mc_visible_downloads', 'downloads'],
        'featured' => ['idx_mc_visible_updated', 'updated_at'],
    ];

    foreach ($queries as $key => $query) {
        $after = $db->query($query)->fetchAll(PDO::FETCH_COLUMN);
        if ($after !== $before[$key]) {
            throw new RuntimeException($key . ': candidate index changed result order');
        }

        // Forced plan tests whether the candidate physically supports
        // visibility filtering + requested sorting without filesort.
        [$index, $column] = $indexes[$key];
        $forced = str_replace(
            'FROM levels', 'FROM levels FORCE INDEX (' . $index . ')', $query
        );
        $plan = $db->query('EXPLAIN ' . $forced)->fetch(PDO::FETCH_ASSOC);
        $used = (string)($plan['key'] ?? '');
        $extra = (string)($plan['Extra'] ?? '');
        if ($used !== $index || stripos($extra, 'filesort') !== false) {
            throw new RuntimeException(
                $key . ': forced candidate index not usable: ' . json_encode($plan)
            );
        }
        echo 'PASS ' . $key . ' uses ' . $used
            . ' without filesort, preserving sorted IDs' . PHP_EOL;
    }

    echo "MUCHOCORE_LEVEL_INDEX_STAGING_OK\n";
} finally {
    $root->exec('DROP DATABASE IF EXISTS ' . $name);
}
