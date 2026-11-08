<?php

declare(strict_types=1);

/**
 * Download/upload reliability, isolated PHP 8 + MariaDB test.
 * In particular, a failed counter/cache service must never make the game
 * treat a successfully encoded/persisted level as failed.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Cache\CacheInterface;
use MuchoCore\Compatibility\Legacy10IdentityService;
use MuchoCore\Job\JobQueue;
use MuchoCore\Level\LevelDownloadTracker;
use MuchoCore\Level\LevelRevisionService;
use MuchoCore\Level\LevelTransferRepository;
use MuchoCore\Level\LevelTransferService;
use MuchoCore\Level\LevelValidator;
use MuchoCore\Protocol\GdLevelDownloadEncoder;
use MuchoCore\Search\LevelSearchIndexer;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('Level reliability integration requires isolated CI MariaDB.');
}

$connect = static fn(?string $name): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port .
    ($name === null ? '' : ';dbname=' . $name) . ';charset=utf8mb4',
    'root',
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$check = static function (bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException('PHP 8 level reliability: ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
};

$name = 'mc_level_reliable_' . bin2hex(random_bytes(5));
$server = $connect(null);
$db = null;

try {
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db = $connect($name);

    $db->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        username VARCHAR(20) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE profiles (
        user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL UNIQUE
    ) ENGINE=InnoDB');
    $db->exec(<<<'SQL'
CREATE TABLE levels (
    level_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(64) NOT NULL,
    description TEXT NOT NULL,
    level_data LONGTEXT NOT NULL,
    level_version INT NOT NULL DEFAULT 1,
    game_version INT NOT NULL DEFAULT 22,
    binary_version INT NOT NULL DEFAULT 51,
    length INT NOT NULL DEFAULT 0,
    difficulty INT NOT NULL DEFAULT 0,
    audio_track INT NOT NULL DEFAULT 0,
    song_id INT NOT NULL DEFAULT 0,
    auto_level TINYINT NOT NULL DEFAULT 0,
    copy_password VARCHAR(64) NOT NULL DEFAULT '0',
    original_level_id BIGINT NOT NULL DEFAULT 0,
    two_player TINYINT NOT NULL DEFAULT 0,
    object_count INT NOT NULL DEFAULT 0,
    coins INT NOT NULL DEFAULT 0,
    coins_verified TINYINT NOT NULL DEFAULT 0,
    requested_stars INT NOT NULL DEFAULT 0,
    is_unlisted TINYINT NOT NULL DEFAULT 0,
    unlisted2 TINYINT NOT NULL DEFAULT 0,
    wt BIGINT NOT NULL DEFAULT 0,
    wt2 BIGINT NOT NULL DEFAULT 0,
    extra_string TEXT NOT NULL,
    level_info TEXT NOT NULL,
    settings_string TEXT NOT NULL,
    song_ids TEXT NOT NULL,
    sfx_ids TEXT NOT NULL,
    ldm TINYINT NOT NULL DEFAULT 0,
    ts BIGINT NOT NULL DEFAULT 0,
    downloads INT NOT NULL DEFAULT 0,
    likes INT NOT NULL DEFAULT 0,
    demon INT NOT NULL DEFAULT 0,
    demon_difficulty INT NOT NULL DEFAULT 0,
    stars INT NOT NULL DEFAULT 0,
    featured INT NOT NULL DEFAULT 0,
    epic INT NOT NULL DEFAULT 0,
    is_deleted TINYINT NOT NULL DEFAULT 0,
    created_at VARCHAR(32) NOT NULL DEFAULT '2026-11-08 12:00:00',
    updated_at VARCHAR(32) NOT NULL DEFAULT '2026-11-08 12:00:00',
    KEY idx_levels_owner (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

    foreach ([1 => 'Owner', 2 => 'Other'] as $id => $user) {
        $stmt = $db->prepare(
            'INSERT INTO accounts(account_id,username,password_hash,gjp2_hash)
             VALUES (?,?,?,?)'
        );
        $stmt->execute([
            $id, $user, password_hash('pass-' . $id, PASSWORD_DEFAULT),
            password_hash(sha1('pass-' . $id . 'mI29fmAnxgTs'), PASSWORD_DEFAULT),
        ]);
        $db->prepare('INSERT INTO profiles(account_id) VALUES (?)')->execute([$id]);
    }

    // Deliberately unavailable cache backend: DB writes must still succeed.
    $failingCache = new class implements CacheInterface {
        public function get(string $key): ?string { return null; }
        public function set(string $key, string $value, int $ttlSeconds): void {}
        public function delete(string $key): void {}
        public function deletePrefix(string $prefix): void {
            throw new RuntimeException('Simulated optional cache outage.');
        }
    };

    $service = new LevelTransferService(
        $db,
        new AccountAuthenticator($db),
        new Legacy10IdentityService($db),
        new LevelTransferRepository($db),
        new LevelDownloadTracker($db),
        new GdLevelDownloadEncoder(),
        new LevelValidator(),
        new LevelRevisionService($db),
        new LevelSearchIndexer($db),
        $failingCache,
        new JobQueue($db),
    );

    $data = [
        'gameVersion' => '22',
        'binaryVersion' => '51',
        'levelName' => 'GD 2209 reliability',
        'levelString' => 'kS1,1,2;1,2,3;',
        'levelVersion' => '1',
        'levelDesc' => 'SGVsbG8=',
        'objects' => '2',
    ];
    $id = $service->upload(1, 'pass-1', $data);
    $check($id > 0, 'upload returns saved ID despite unavailable cache/index/job backends');
    $level = $db->query('SELECT level_data FROM levels WHERE level_id=' . $id)->fetchColumn();
    $check($level === $data['levelString'], 'upload persists unmodified level data');
    $check($service->checkUpdate($id, 1) === '1', 'new version check is available');

    $rejected = false;
    try {
        $service->upload(2, 'pass-2', $data + ['levelID' => $id]);
    } catch (RuntimeException $error) {
        $rejected = str_contains($error->getMessage(), 'ownership');
    }
    $check($rejected, 'another account cannot overwrite level by ID');
    $check($db->query('SELECT level_data FROM levels WHERE level_id=' . $id)->fetchColumn()
        === $data['levelString'], 'rejected owner change preserves level data');

    $updatedData = $data;
    $updatedData['levelID'] = (string)$id;
    $updatedData['levelString'] = 'kS1,1,2;1,3,4;';
    $updatedData['levelVersion'] = '2';
    $check($service->upload(1, 'pass-1', $updatedData) === $id,
        'owner update succeeds with broken cache');
    $check($service->checkUpdate($id, 1) === '2',
        'version check announces updated level');
    $check($db->query('SELECT level_data FROM levels WHERE level_id=' . $id)->fetchColumn()
        === $updatedData['levelString'], 'owner update persists new bytes');

    $downloads = static fn(): int =>
        (int)$db->query('SELECT downloads FROM levels WHERE level_id=' . $id)->fetchColumn();

    // The dedupe table does not exist yet. The GD wire must still be served.
    $wire = $service->download($id, 22, 51, false, true, 0, '', '192.0.2.10');
    $check(str_starts_with($wire, '1:' . $id . ':'),
        'download succeeds without counter infrastructure');
    $check(str_contains($wire, ':62:') && str_contains($wire, ':63:'),
        'GD 2.209 timestamp fields survive counter outage');
    $check($downloads() === 0, 'failed counter update does not affect level data');

    (require dirname(__DIR__, 2) .
        '/database/migrations/20261007_001_level_download_dedup.php')($db);

    // Malformed stored data should not count as a successful download.
    $db->exec("UPDATE levels SET created_at='not-a-valid-date' WHERE level_id=" . $id);
    $encodeRejected = false;
    try {
        $service->download($id, 22, 51, false, true, 0, '', '192.0.2.11');
    } catch (Throwable) {
        $encodeRejected = true;
    }
    $check($encodeRejected && $downloads() === 0,
        'encoding failure cannot inflate level downloads');
    $db->exec("UPDATE levels SET created_at='2026-11-08 12:00:00' WHERE level_id=" . $id);

    $check($service->download($id, 22, 51, false, false, 0, '', '192.0.2.12') !== '-1',
        'normal download with inc=0 succeeds');
    $check($downloads() === 0, 'inc=0 does not affect counter');

    foreach ([1 => 1, 2 => 2, 3 => 2] as $request => $expected) {
        $check($service->download($id, 22, 51, false, true, 0, '', '192.0.2.13') !== '-1',
            'download request ' . $request . ' succeeds');
        $check($downloads() === $expected,
            'paired download counter capped at ' . $expected);
    }
    $check($service->download($id, 22, 51, false, true, 0, '', '192.0.2.14') !== '-1',
        'separate IP downloads independently');
    $check($downloads() === 3, 'separate IP claims independent slot');

    $check($service->updateDescription($id, 1, 'pass-1', 'VXBkYXRlZA==', 22),
        'description edit returns success despite cache outage');
    $check((string)$db->query('SELECT description FROM levels WHERE level_id=' . $id)->fetchColumn() !== '',
        'description edit is persisted');
    $check($service->delete($id, 1, 'pass-1'),
        'owner delete returns success despite cache outage');
    $check((int)$db->query('SELECT is_deleted FROM levels WHERE level_id=' . $id)->fetchColumn() === 1,
        'deleted level is marked in database');
    $check($service->download($id, 22, 51, false, true, 0, '', '192.0.2.15') === '-1',
        'deleted levels cannot be downloaded');
    $check($downloads() === 3, 'deleted level cannot inflate downloads');

    echo "MUCHOCORE_PHP8_LEVEL_TRANSFER_RELIABILITY_OK\n";
} finally {
    $db = null;
    $server->exec('DROP DATABASE IF EXISTS ' . $name);
}
