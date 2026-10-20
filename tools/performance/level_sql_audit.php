<?php

declare(strict_types=1);

/**
 * Read-only query-plan audit for MuchoCore level-search hot paths.
 * Use on a staging database first. EXPLAIN does not execute the SELECTs,
 * mutate records, or change database indexes.
 *
 * php tools/performance/level_sql_audit.php
 */

// Also support piping the script into 'docker compose exec -T app php'
// from a VPS checkout, without copying files into a live container.
$root = dirname(__DIR__, 2);
if (!is_file($root . '/vendor/autoload.php')) {
    $root = getcwd() ?: '';
}
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Run from the MuchoCore project root inside the PHP container.\n");
    exit(2);
}
require $root . '/vendor/autoload.php';

use MuchoCore\Database\Database;

$pdo = (new Database())->connection();
$queries = [
    'popular' => 'SELECT l.level_id FROM levels l
        WHERE l.is_deleted=0 AND l.is_unlisted=0
        ORDER BY l.likes DESC,l.level_id DESC LIMIT 10',
    'most_downloaded' => 'SELECT l.level_id FROM levels l
        WHERE l.is_deleted=0 AND l.is_unlisted=0
        ORDER BY l.downloads DESC,l.level_id DESC LIMIT 10',
    'recent' => 'SELECT l.level_id FROM levels l
        WHERE l.is_deleted=0 AND l.is_unlisted=0
        ORDER BY l.created_at DESC,l.level_id DESC LIMIT 10',
    'featured' => 'SELECT l.level_id FROM levels l
        WHERE l.is_deleted=0 AND l.is_unlisted=0 AND l.featured>0
        ORDER BY l.updated_at DESC,l.level_id DESC LIMIT 10',
    'visible_count' => 'SELECT COUNT(*) FROM levels l
        WHERE l.is_deleted=0 AND l.is_unlisted=0',
];

$plans = [];
foreach ($queries as $name => $sql) {
    $explain = $pdo->query('EXPLAIN ' . $sql);
    $plans[$name] = array_map(
        static fn(array $row): array => [
            'table' => (string)($row['table'] ?? ''),
            'access_type' => (string)($row['type'] ?? ''),
            'possible_keys' => (string)($row['possible_keys'] ?? ''),
            'key' => (string)($row['key'] ?? ''),
            'rows_estimate' => (int)($row['rows'] ?? 0),
            'extra' => (string)($row['Extra'] ?? ''),
        ],
        $explain->fetchAll(PDO::FETCH_ASSOC)
    );
}

$indexes = [];
foreach ($pdo->query('SHOW INDEX FROM levels')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $index = (string)($row['Key_name'] ?? '');
    if ($index === '') {
        continue;
    }
    $indexes[$index][(int)($row['Seq_in_index'] ?? 0)] = [
        'column' => (string)($row['Column_name'] ?? ''),
        'direction' => (string)($row['Collation'] ?? ''),
    ];
}
ksort($indexes);
foreach ($indexes as &$columns) {
    ksort($columns);
    $columns = array_values($columns);
}
unset($columns);

echo json_encode([
    'report' => 'muchocore-level-readonly-index-audit-v1',
    'database_version' => (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
    'indexes' => $indexes,
    'query_plans' => $plans,
    'notice' => 'Estimates, not timings. No player rows or secrets collected; no schema changes.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
