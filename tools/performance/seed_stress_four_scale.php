<?php

declare(strict_types=1);

/**
 * Synthetic workload only. Never run against an existing GDPS database.
 *
 * Prerequisites:
 *  - freshly initialized dedicated mucho_stress database;
 *  - seed_stress_fixture.php created StressRobot + base level ID 1;
 *  - MUCHO_SYNTHETIC_SCALE_RUN=1 explicitly set by the isolated runner.
 *
 * Progression is monotonic: 1000, 2000, 5000, 10000 accounts AND levels.
 */
use MuchoCore\Database\Database;
use MuchoCore\Search\LevelSearchIndexer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$arg = $argv[1] ?? '';
if (
    PHP_SAPI !== 'cli' ||
    count($argv) !== 2 ||
    !in_array($arg, ['--size=1000', '--size=2000', '--size=5000', '--size=10000'], true) ||
    getenv('DB_NAME') !== 'mucho_stress' ||
    getenv('MUCHO_SYNTHETIC_SCALE_RUN') !== '1'
) {
    fwrite(STDERR, "REFUSED: isolated mucho_stress only, --size=1000|2000|5000|10000\n");
    exit(2);
}
$target = (int)substr($arg, strlen('--size='));
$db = (new Database())->connection();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'mucho_stress') {
    throw new RuntimeException('Database identity mismatch: refusing synthetic writes.');
}

$counts = [];
foreach (['accounts', 'profiles', 'levels'] as $table) {
    $counts[$table] = (int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
if (
    $counts['accounts'] < 1 ||
    $counts['accounts'] !== $counts['levels'] ||
    $counts['accounts'] !== $counts['profiles'] ||
    $counts['accounts'] > $target
) {
    throw new RuntimeException('Unexpected stress fixture cardinality: refusing writes.');
}
$current = $counts['accounts'];
$maxAccount = (int)$db->query('SELECT MAX(account_id) FROM accounts')->fetchColumn();
$maxLevel = (int)$db->query('SELECT MAX(level_id) FROM levels')->fetchColumn();
if ($maxAccount !== $current || $maxLevel !== $current) {
    throw new RuntimeException('Stress fixture IDs are not contiguous: refusing writes.');
}

// Reject any non-synthetic fixture. Fixed format prevents accidental writes
// into a real tenant, even if someone reused the stress database name.
$unexpectedAccounts = (int)$db->query("
    SELECT COUNT(*) FROM accounts
    WHERE NOT (
        (account_id=1 AND username='StressRobot'
            AND email='stress-robot@example.invalid')
        OR
        (account_id BETWEEN 2 AND 10000
         AND username=CONCAT('StressUser',LPAD(account_id,5,'0'))
         AND email=CONCAT('stress-user-',LPAD(account_id,5,'0'),'@example.invalid')
         AND password_hash='disabled')
    )
")->fetchColumn();
$unexpectedLevels = (int)$db->query("
    SELECT COUNT(*) FROM levels
    WHERE NOT (
        (level_id=1 AND account_id=1 AND name='Synthetic Stress Level')
        OR
        (level_id BETWEEN 2 AND 10000
         AND account_id=level_id
         AND name=CONCAT('Synthetic Stress Level ',LPAD(level_id,5,'0')))
    )
")->fetchColumn();
$unexpectedProfiles = (int)$db->query("
    SELECT COUNT(*) FROM profiles
    WHERE user_id <> account_id
")->fetchColumn();
if ($unexpectedAccounts || $unexpectedLevels || $unexpectedProfiles) {
    throw new RuntimeException('Non-synthetic or inconsistent data exists: refusing writes.');
}

$start = microtime(true);
$accountInsert = $db->prepare(
    'INSERT INTO accounts (account_id,username,email,password_hash)
     VALUES (:id,:username,:email,:password_hash)'
);
$profileInsert = $db->prepare(
    'INSERT INTO profiles (user_id,account_id) VALUES (:id,:account_id)'
);
$levelInsert = $db->prepare(
    'INSERT INTO levels
      (level_id,account_id,name,description,level_data,game_version,object_count,
       downloads,likes)
     VALUES (:id,:account_id,:name,:description,:data,22,10,:downloads,:likes)'
);

for ($first = $current + 1; $first <= $target; $first += 250) {
    $db->beginTransaction();
    try {
        for ($id = $first; $id <= min($target, $first + 249); ++$id) {
            $accountInsert->execute([
                'id' => $id,
                'username' => sprintf('StressUser%05d', $id),
                'email' => sprintf('stress-user-%05d@example.invalid', $id),
                'password_hash' => 'disabled',
            ]);
            $profileInsert->execute(['id' => $id, 'account_id' => $id]);
            $levelInsert->execute([
                'id' => $id,
                'account_id' => $id,
                'name' => sprintf('Synthetic Stress Level %05d', $id),
                'description' => 'Synthetic benchmark only',
                'data' => '1,2,3;',
                'downloads' => $id * 7 % 5003,
                'likes' => $id * 13 % 2999,
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

$actual = [];
foreach (['accounts', 'profiles', 'levels'] as $table) {
    $actual[$table] = (int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    if ($actual[$table] !== $target) {
        throw new RuntimeException('Unexpected row count in ' . $table);
    }
}

$insertSeconds = round(microtime(true) - $start, 3);
$indexStart = microtime(true);
$indexed = (new LevelSearchIndexer($db))->rebuild($target);
$indexCount = (int)$db->query('SELECT COUNT(*) FROM mucho_level_search_index')->fetchColumn();
if ($indexed !== $target || $indexCount !== $target) {
    throw new RuntimeException('Level search index incomplete: refusing benchmark.');
}
$indexSeconds = round(microtime(true) - $indexStart, 3);

echo json_encode([
    'stage' => $target,
    'accounts' => $actual['accounts'],
    'profiles' => $actual['profiles'],
    'levels' => $actual['levels'],
    'level_search_index' => $indexCount,
    'insert_seconds' => $insertSeconds,
    'reindex_seconds' => $indexSeconds,
    'synthetic_only' => true,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
