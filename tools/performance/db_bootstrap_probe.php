<?php

declare(strict_types=1);

/**
 * Safe, read-only per-process performance probe.
 *
 * From a production checkout WITHOUT checking out the performance branch:
 *   git show FETCH_HEAD:tools/performance/db_bootstrap_probe.php \
 *     | docker compose exec -T app php
 *
 * It does not log database credentials, request contents, user data or IPs.
 * This PHP-CLI measurement is NOT a PHP-FPM request latency measurement.
 */

$root = getcwd() ?: '';
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "ERROR: run from the MuchoCore project root in the app container.\n");
    exit(2);
}

$now = static fn(): int => hrtime(true);
$ms = static fn(int $start, int $end): float => round(($end - $start) / 1e6, 3);

$t0 = $now();
require $root . '/vendor/autoload.php';
$t1 = $now();

// Prime Environment::get for the exact keys needed by Database.
// This separates dotenv/runtime-env parsing from the PDO connection cost.
// Never print the values or log secrets.
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $name) {
    \MuchoCore\Core\Environment::get($name);
}
$t2 = $now();

$pdo = (new \MuchoCore\Database\Database())->connection();
$t3 = $now();
$read = $pdo->prepare('SELECT 1');
for ($i = 0; $i < 20; $i++) {
    $read->execute();
    if ((int)$read->fetchColumn() !== 1) {
        throw new RuntimeException('Unexpected SELECT 1 result.');
    }
    $read->closeCursor();
}
$t4 = $now();

echo json_encode([
    'report' => 'muchocore-db-bootstrap-cli-v1',
    'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
    'autoload_ms' => $ms($t0, $t1),
    'configuration_ms' => $ms($t1, $t2),
    'pdo_connect_ms' => $ms($t2, $t3),
    'twenty_select_1_ms' => $ms($t3, $t4),
    'per_query_ms' => round(($t4 - $t3) / 20e6, 3),
    'total_ms' => $ms($t0, $t4),
    'measurement' => 'single PHP CLI process, after interpreter start; no FPM or network HTTP',
    'mutates_database' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
