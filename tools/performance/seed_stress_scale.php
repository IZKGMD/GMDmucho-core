<?php

declare(strict_types=1);

// Add levels only to a dedicated, already running mucho_stress fixture.
// Never accept a production database name or an arbitrary row target.
use MuchoCore\Database\Database;
use MuchoCore\Search\LevelSearchIndexer;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$arg = $argv[1] ?? '';
if (getenv('DB_NAME') !== 'mucho_stress' ||
    !in_array($arg, ['--levels=1000', '--levels=10000'], true) ||
    count($argv) !== 2
) {
    fwrite(STDERR, "Use only in mucho_stress: php tools/performance/seed_stress_scale.php --levels=1000|10000\n");
    exit(2);
}
$target = (int) substr($arg, strlen('--levels='));
$db = (new Database())->connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$account = $db->query('SELECT username FROM accounts WHERE account_id=1')->fetchColumn();
$fixture = $db->query('SELECT name FROM levels WHERE level_id=1')->fetchColumn();
if ($account !== 'StressRobot' || $fixture !== 'Synthetic Stress Level') {
    fwrite(STDERR, "REFUSED: base StressRobot fixture is not present. Run the basic stress suite first.\n");
    exit(1);
}

$total = (int) $db->query('SELECT COUNT(*) FROM levels')->fetchColumn();
if ($total > $target) {
    fwrite(STDERR, "REFUSED: database already contains {$total} levels, more than requested {$target}. Use --levels=10000 to rerun the larger fixture.\n");
    exit(1);
}
$unexpected = (int) $db->query(
    "SELECT COUNT(*) FROM levels WHERE account_id<>1 OR name NOT LIKE 'Synthetic Stress Level%'"
)->fetchColumn();
if ($unexpected !== 0) {
    fwrite(STDERR, "REFUSED: non-synthetic levels detected.\n");
    exit(1);
}

$insert = $db->prepare(
    'INSERT IGNORE INTO levels
      (level_id,account_id,name,description,level_data,game_version,object_count)
     VALUES (:id,1,:name,:description,:data,22,10)'
);
$inserted = 0;
$start = microtime(true);
for ($id = 2; $id <= $target; $id += 250) {
    $db->beginTransaction();
    try {
        for ($next = $id; $next < min($id + 250, $target + 1); ++$next) {
            $insert->execute([
                'id' => $next,
                'name' => sprintf('Synthetic Stress Level %05d', $next),
                'description' => 'Synthetic benchmark only',
                'data' => '1,2,3;',
            ]);
            $inserted += $insert->rowCount();
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    if ($id === 2 || (($id - 2) % 2500) === 0) {
        echo "SEED_PROGRESS=" . min($target, $id + 249) . "/{$target}\n";
    }
}
$actual = (int) $db->query('SELECT COUNT(*) FROM levels')->fetchColumn();
if ($actual !== $target) {
    fwrite(STDERR, "REFUSED: expected {$target} levels but found {$actual}.\n");
    exit(1);
}

echo "SEED_LEVELS={$actual} INSERTED={$inserted} SEED_SEC=" . round(microtime(true) - $start, 2) . "\n";
$reindexStart = microtime(true);
$indexed = (new LevelSearchIndexer($db))->rebuild($target);
echo "SEARCH_INDEXED={$indexed} REINDEX_SEC=" . round(microtime(true) - $reindexStart, 2) . "\n";
if ($indexed !== $target) {
    fwrite(STDERR, "Search index incomplete; stopping before benchmark.\n");
    exit(1);
}
echo "SYNTHETIC_SCALE_READY={$target}\n";
