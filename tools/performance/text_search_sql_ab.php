<?php

declare(strict_types=1);

/**
 * Read-only, interleaved SQL microbenchmark of real GD 2.209 text search.
 * Compares index choice on the same isolated 10k-row stress fixture.
 * This is not HTTP end-to-end performance or a player-capacity test.
 */
use MuchoCore\Database\Database;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
if (PHP_SAPI !== 'cli' || getenv('DB_NAME') !== 'mucho_stress'
    || is_file(dirname(__DIR__, 2) . '/.env')) {
    throw new RuntimeException('REFUSED: isolated mucho_stress only.');
}
$db = (new Database())->connection();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'mucho_stress') {
    throw new RuntimeException('Wrong active database.');
}
$index = (int)$db->query(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='levels'
       AND index_name='idx_mc_text_visible_ranked'"
)->fetchColumn();
if ($index !== 7) {
    throw new RuntimeException('Expected seven-column candidate index absent.');
}
$rows = (int)$db->query('SELECT COUNT(*) FROM levels')->fetchColumn();
if ($rows !== 10000) {
    throw new RuntimeException('Expected exactly 10000 synthetic levels.');
}

$variants = [
    'baseline' => 'IGNORE INDEX (idx_mc_text_visible_ranked)',
    'candidate' => 'FORCE INDEX (idx_mc_text_visible_ranked)',
    'optimizer' => '',
];
$cases = [
    'count_common' => ['projection' => 'COUNT(*)', 'term' => 'Synthetic Stress Level', 'suffix' => ''],
    'count_rare' => ['projection' => 'COUNT(*)', 'term' => '00099', 'suffix' => ''],
    'page_common' => ['projection' => 'l.level_id', 'term' => 'Synthetic Stress Level',
                      'suffix' => ' ORDER BY l.likes DESC,l.level_id DESC LIMIT 10'],
];
$measurements = [];
foreach ($cases as $caseName => $case) {
    $stmts = [];
    foreach ($variants as $variant => $hint) {
        $sql = 'SELECT ' . $case['projection'] . ' FROM levels l ' . $hint
            . ' WHERE l.is_deleted=0 AND l.is_unlisted=0 AND l.unlisted2=0'
            . ' AND l.game_version<=22 AND l.name LIKE :search' . $case['suffix'];
        $stmts[$variant] = $db->prepare($sql);
        $measurements[$caseName][$variant] = [];
    }
    $expected = null;
    for ($iteration = 0; $iteration < 30; $iteration++) {
        $order = array_keys($variants);
        $rotation = $iteration % count($order);
        $order = array_merge(array_slice($order, $rotation), array_slice($order, 0, $rotation));
        foreach ($order as $variant) {
            $start = hrtime(true);
            $stmts[$variant]->execute(['search' => '%' . $case['term'] . '%']);
            $value = $case['suffix'] === ''
                ? (int)$stmts[$variant]->fetchColumn()
                : array_map('intval', $stmts[$variant]->fetchAll(PDO::FETCH_COLUMN));
            $stmts[$variant]->closeCursor();
            $ms = (hrtime(true) - $start) / 1000000;
            if ($expected === null) {
                $expected = $value;
            } elseif ($expected !== $value) {
                throw new RuntimeException('Result mismatch for ' . $caseName);
            }
            if ($iteration >= 5) {
                $measurements[$caseName][$variant][] = $ms;
            }
        }
    }
}

$report = [
    'kind' => 'muchocore-real-gd-text-sql-index-ab-v2',
    'scope' => 'isolated 10000-level PDO SQL only',
    'samples_per_variant' => 25,
    'all_results_identical' => true,
    'cases' => [],
];
foreach ($measurements as $caseName => $results) {
    foreach ($results as $variant => $samples) {
        sort($samples, SORT_NUMERIC);
        $report['cases'][$caseName][$variant] = [
            'p50_ms' => round($samples[12], 3),
            'p95_ms' => round($samples[23], 3),
            'min_ms' => round($samples[0], 3),
            'max_ms' => round($samples[24], 3),
        ];
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
