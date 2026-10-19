<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'MuchoCore\\')) {
        require dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, 10)) . '.php';
    }
});

use MuchoCore\Cache\DatabaseCache;
use MuchoCore\Job\JobQueue;

function storageCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}

// Pause a real SELECT after fetching its snapshot, then let another DB
// connection write. This reproduces both cache races without timing sleeps.
final class CacheSnapshotStatement extends PDOStatement
{
    public static ?Closure $afterFetch = null;

    protected function __construct() {}

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        if (str_starts_with($this->queryString, 'SELECT cache_value') && self::$afterFetch !== null) {
            $callback = self::$afterFetch;
            self::$afterFetch = null;
            $callback();
        }
        return $row;
    }
}

$host = getenv('TEST_DB_HOST');
$port = getenv('TEST_DB_PORT') ?: '3306';
$password = getenv('TEST_DB_ROOT_PASSWORD');
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('Requires an isolated local/CI database, never production.');
}
$connect = static fn(?string $db): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port . ($db === null ? '' : ';dbname=' . $db) . ';charset=utf8mb4',
    'root', $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

if (($argv[1] ?? '') === '--drain') {
    $name = $argv[2] ?? '';
    if (preg_match('/^mc_storage_test_[a-f0-9]{12}$/D', $name) !== 1) {
        throw new RuntimeException('Invalid isolated worker database.');
    }
    $queue = new JobQueue($connect($name));
    $worker = $argv[3];
    $claimed = [];
    while (($job = $queue->reserve($worker)) !== null) {
        $id = (int)$job['id'];
        if (!$queue->complete($id, $worker, (int)$job['attempts'])) {
            throw new RuntimeException('Concurrent worker lost its reservation.');
        }
        $claimed[] = $id;
    }
    echo json_encode($claimed, JSON_THROW_ON_ERROR);
    exit(0);
}

$name = 'mc_storage_test_' . bin2hex(random_bytes(6));
$server = $connect(null);
$children = [];
try {
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4');
    $pdo = $connect($name);
    $pdo->exec('CREATE TABLE accounts (account_id BIGINT UNSIGNED PRIMARY KEY,username VARCHAR(20)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE levels (
        level_id BIGINT UNSIGNED PRIMARY KEY,account_id BIGINT UNSIGNED,name VARCHAR(128),
        game_version INT,downloads INT NOT NULL DEFAULT 0,is_deleted TINYINT,is_unlisted TINYINT) ENGINE=InnoDB');
    $migration = require dirname(__DIR__, 2) . '/database/migrations/20260928_001_intelligence_scale.php';
    $migration->up($pdo);
    // Download counters use indexed client slots and must not break the
    // exact two-downloads-per-client compatibility contract.
    $downloadMigration = require dirname(__DIR__, 2)
        . '/database/migrations/20261007_001_level_download_dedup.php';
    $downloadMigration($pdo);
    $pdo->exec(
        "INSERT INTO levels(level_id,account_id,name,game_version,is_deleted,is_unlisted)
         VALUES(99,2,'Download test',22,0,0)"
    );
    $tracker = new \MuchoCore\Level\LevelDownloadTracker($pdo);
    storageCheck(
        $tracker->record(99, '192.0.2.101') &&
        $tracker->record(99, '192.0.2.101') &&
        !$tracker->record(99, '192.0.2.101') &&
        (int)$pdo->query('SELECT downloads FROM levels WHERE level_id=99')->fetchColumn() === 2,
        'download dedupe permits exactly two client slots per window'
    );
    $pdo->exec(
        'UPDATE mucho_level_downloads
         SET created_at=DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 400 SECOND)
         WHERE level_id=99'
    );
    storageCheck(
        $tracker->record(99, '192.0.2.101') &&
        (int)$pdo->query('SELECT downloads FROM levels WHERE level_id=99')->fetchColumn() === 3,
        'expired slots are reusable without increasing unrelated row locks'
    );

    // Maintenance is performed by the background worker, not during
    // record(): it should prune only a bounded number of expired rows.
    $pdo->exec(
        'UPDATE mucho_level_downloads
         SET created_at=DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 400 SECOND)
         WHERE level_id=99'
    );
    $pdo->exec(
        "INSERT INTO mucho_level_downloads(level_id,client_hash,slot)
         VALUES (99,'" . str_repeat('f', 64) . "',1)"
    );
    storageCheck(
        \MuchoCore\Level\LevelDownloadTracker::pruneExpired($pdo, 1) === 1,
        'background download pruning is limited to a single expired row'
    );
    storageCheck(
        (int)$pdo->query(
            "SELECT COUNT(*) FROM mucho_level_downloads
             WHERE level_id=99 AND client_hash='" . str_repeat('f', 64) . "'"
        )->fetchColumn() === 1
        && (int)$pdo->query(
            'SELECT downloads FROM levels WHERE level_id=99'
        )->fetchColumn() === 3,
        'background pruning preserves active dedupe slots and download count'
    );

    $writer = $connect($name);
    $cache = new DatabaseCache($pdo);
    $otherCache = new DatabaseCache($writer);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CacheSnapshotStatement::class]);
    $cache->set('expired', 'old', 0);
    $writer->exec("UPDATE mucho_cache SET expires_at=1 WHERE cache_key='expired'");
    CacheSnapshotStatement::$afterFetch = static fn() => $otherCache->set('expired', 'fresh', 60);
    storageCheck($cache->get('expired') === null && $cache->get('expired') === 'fresh',
        'expired snapshot cleanup preserves a concurrent refresh');
    CacheSnapshotStatement::$afterFetch = static fn() => $otherCache->set('missing', 'created', 0);
    storageCheck($cache->get('missing') === null && $cache->get('missing') === 'created',
        'cache miss preserves a concurrently inserted entry');
    $cache->set('empty', '', 0);
    storageCheck($cache->get('empty') === '', 'empty non-expiring cache values are valid hits');
    $writer->exec("UPDATE mucho_cache SET expires_at=1 WHERE cache_key='empty'");
    storageCheck($cache->get('empty') === null &&
        (int)$writer->query("SELECT COUNT(*) FROM mucho_cache WHERE cache_key='empty'")->fetchColumn() === 0,
        'expired entries are removed');
    foreach (['unit%_!:one', 'unit%_!:two', 'unitAA!:other', 'unrelated'] as $key) {
        $cache->set($key, $key, 0);
    }
    $cache->deletePrefix('unit%_!:');
    storageCheck($cache->get('unit%_!:one') === null && $cache->get('unit%_!:two') === null &&
        $cache->get('unitAA!:other') !== null && $cache->get('unrelated') !== null,
        'database prefix invalidation treats SQL wildcards literally');

    $queue = new JobQueue($pdo);
    $otherQueue = new JobQueue($writer);
    // Differing session time zones must not move the availability/lease clocks.
    $pdo->exec("SET time_zone='+07:00'");
    $writer->exec("SET time_zone='-04:00'");
    $id = $queue->enqueue('unit.job', ['text' => 'Привет'], 60);
    storageCheck($otherQueue->reserve('other') === null, 'delayed jobs stay queued across session time zones');
    $writer->exec("UPDATE mucho_jobs SET available_at=UTC_TIMESTAMP() WHERE id={$id}");
    $job = $otherQueue->reserve('owner');
    storageCheck($job !== null && (int)$job['id'] === $id && $job['payload']['text'] === 'Привет',
        'ready jobs preserve JSON payloads');
    storageCheck(!$queue->complete($id, 'stranger', 1) && !$queue->fail($id, 'wrong owner', 'stranger', 1),
        'unrelated workers cannot complete or fail a running job');
    $writer->exec("UPDATE mucho_jobs SET locked_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 11 MINUTE) WHERE id={$id}");
    storageCheck($queue->retryStale() === 1, 'stale leases recover across session time zones');
    $next = $queue->reserve('owner');
    storageCheck($next !== null && (int)$next['attempts'] === 2 &&
        !$otherQueue->complete($id, 'owner', 1) && !$otherQueue->fail($id, 'old attempt', 'owner', 1),
        'previous attempts are fenced even when a worker ID is reused');
    storageCheck($queue->complete($id, 'owner', 2) && !$queue->fail($id, 'late failure', 'owner', 2),
        'current owner completes once and late failure cannot resurrect the job');
    $row = $writer->query("SELECT * FROM mucho_jobs WHERE id={$id}")->fetch();
    storageCheck($row['worker_id'] === null && $row['locked_at'] === null && $row['last_error'] === null,
        'completion clears lease and previous error');

    $crashing = $queue->enqueue('unit.crash');
    for ($attempt = 1; $attempt <= JobQueue::MAX_ATTEMPTS; ++$attempt) {
        $job = $queue->reserve('crashing');
        storageCheck($job !== null && (int)$job['id'] === $crashing && (int)$job['attempts'] === $attempt,
            'crashed job attempt ' . $attempt . ' is claimed exactly once');
        $writer->exec("UPDATE mucho_jobs SET locked_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 11 MINUTE) WHERE id={$crashing}");
        $queue->retryStale();
    }
    storageCheck($queue->reserve('crashing') === null &&
        $writer->query("SELECT status FROM mucho_jobs WHERE id={$crashing}")->fetchColumn() === 'failed',
        'repeated worker crashes stop at the attempt limit');
    $retry = $queue->enqueue('unit.retry');
    $queue->reserve('retry');
    storageCheck($queue->fail($retry, 'temporary', 'retry', 1, 5, 2) && $queue->reserve('retry') === null,
        'failed attempt observes its retry delay');
    $writer->exec("UPDATE mucho_jobs SET available_at=UTC_TIMESTAMP() WHERE id={$retry}");
    $queue->reserve('retry');
    storageCheck($queue->fail($retry, 'permanent', 'retry', 2, 5, 2) &&
        $writer->query("SELECT status FROM mucho_jobs WHERE id={$retry}")->fetchColumn() === 'failed',
        'explicit failures stop at the configured attempt limit');

    // A slow transaction holding the oldest job must not block the
    // reservation of the next ready job on modern MariaDB/InnoDB.
    $lockedJob = $queue->enqueue('unit.skip.locked');
    $freeJob = $queue->enqueue('unit.skip.free');
    $pdo->beginTransaction();
    try {
        $pdo->query("SELECT id FROM mucho_jobs WHERE id={$lockedJob} FOR UPDATE")->fetchColumn();
        $writer->exec('SET SESSION innodb_lock_wait_timeout=2');
        $freeClaim = $otherQueue->reserve('skip-locked-worker');
        storageCheck(
            $freeClaim !== null && (int)$freeClaim['id'] === $freeJob,
            'concurrent worker skips a locked oldest queue job'
        );
        storageCheck(
            $otherQueue->complete($freeJob, 'skip-locked-worker', (int)$freeClaim['attempts']),
            'skip-locked worker can complete an independent job'
        );
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    $lockedClaim = $queue->reserve('previously-locked-worker');
    storageCheck(
        $lockedClaim !== null && (int)$lockedClaim['id'] === $lockedJob
        && $queue->complete($lockedJob, 'previously-locked-worker', (int)$lockedClaim['attempts']),
        'previously locked job remains available after the lock releases'
    );

    $pdo->beginTransaction();
    $rejected = false;
    try { $queue->reserve('nested'); } catch (LogicException) { $rejected = true; }
    storageCheck($rejected && $pdo->inTransaction(), 'reservation does not roll back a caller transaction');
    $pdo->rollBack();
    $pdo->exec('RENAME TABLE mucho_jobs TO unavailable_jobs');
    $rejected = false;
    try { $queue->reserve('broken'); } catch (PDOException) { $rejected = true; }
    $pdo->exec('RENAME TABLE unavailable_jobs TO mucho_jobs');
    storageCheck($rejected && !$pdo->inTransaction(), 'database errors are reported rather than mistaken for an empty queue');

    $pdo->exec('TRUNCATE TABLE mucho_jobs');
    $expected = [];
    for ($i = 0; $i < 24; ++$i) { $expected[] = $queue->enqueue('unit.parallel'); }
    for ($i = 0; $i < 4; ++$i) {
        $proc = proc_open([PHP_BINARY, __FILE__, '--drain', $name, 'parallel-' . $i],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) { throw new RuntimeException('Cannot start an isolated worker.'); }
        fclose($pipes[0]);
        $children[] = [$proc, $pipes];
    }
    $claimed = [];
    foreach ($children as [$proc, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0) {
            throw new RuntimeException("Parallel worker failed ({$exit}): {$errors}\n{$output}");
        }
        storageCheck(true, 'parallel worker exits successfully');
        $claimed = array_merge($claimed, json_decode($output, true, 512, JSON_THROW_ON_ERROR));
    }
    $children = [];
    sort($claimed); sort($expected);
    storageCheck($claimed === $expected && (int)$pdo->query("SELECT COUNT(*) FROM mucho_jobs WHERE status='done'")->fetchColumn() === 24,
        'four independent workers process every job exactly once without duplicate claims');
} finally {
    foreach ($children as [$proc, $pipes]) {
        if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
        foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    }
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    $server->exec('DROP DATABASE IF EXISTS ' . $name);
}

echo "CORE_STORAGE_INTEGRATION_OK\n";
