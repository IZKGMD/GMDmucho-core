<?php

declare(strict_types=1);

/**
 * Compare a pinned 2026-11-10 PHP8 SQL schema with the current migration set.
 * Uses only a disposable MariaDB database on the isolated GitHub Actions runner.
 * This is schema-upgrade evidence, not a full live-server version upgrade.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\CloudSave\CloudSaveRepository;
use MuchoCore\Database\Migrator;

$oldPath = getenv('MUCHO_OLD_MIGRATIONS');
$host = getenv('TEST_DB_HOST');
$port = getenv('TEST_DB_PORT');
$password = getenv('TEST_DB_ROOT_PASSWORD');

if (getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('MUCHO_TEST_ALLOW_WRITES') !== '1' ||
    $host !== '127.0.0.1' || $port !== '3306' ||
    !is_string($password) || $password === '' ||
    !is_string($oldPath) || !is_dir($oldPath) ||
    !is_file($oldPath . '/20261108_002_level_reports.php') ||
    is_file($oldPath . '/20261110_001_gd2209_user_info.php')) {
    throw new RuntimeException('Refusing upgrade test outside pinned isolated PHP8 history');
}

$check = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('PHP8 schema upgrade: ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
};

$root = dirname(__DIR__, 2);
$dbName = 'mc_upgrade_' . bin2hex(random_bytes(6));
$keyPath = sys_get_temp_dir() . '/mc-upgrade-key-' . bin2hex(random_bytes(8));
$originalKey = getenv('MUCHO_CLOUDSAVE_KEY_FILE');
$originalEnvKey = $_ENV['MUCHO_CLOUDSAVE_KEY_FILE'] ?? null;
$sourceKey = base64_encode(random_bytes(32)) . PHP_EOL;
if (file_put_contents($keyPath, $sourceKey) === false) {
    throw new RuntimeException('Cannot create disposable Cloud Save key');
}
chmod($keyPath, 0600);
putenv('MUCHO_CLOUDSAVE_KEY_FILE=' . $keyPath);
$_ENV['MUCHO_CLOUDSAVE_KEY_FILE'] = $keyPath;

$pdo = static fn(?string $database): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port .
    ($database === null ? '' : ';dbname=' . $database) . ';charset=utf8mb4',
    'root',
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$server = null;
$db = null;
$created = false;

try {
    $server = $pdo(null);
    $server->exec('CREATE DATABASE ' . $dbName .
        ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $db = $pdo($dbName);

    ob_start();
    try {
        (new Migrator($db, $oldPath))->migrate();
    } finally {
        ob_end_clean();
    }
    $oldCount = (int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $check($oldCount >= 70, 'historical PHP8 schema migrations applied');
    $check((int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mucho_account_user_info'")
        ->fetchColumn() === 0, 'new GD 2.209 table absent before upgrade');

    $userPassword = 'historical-player-ci-password';
    $db->prepare('INSERT INTO accounts
        (account_id, username, email, password_hash)
        VALUES (63011, :username, :email, :hash)')->execute([
            'username' => 'HistoryPlayer',
            'email' => 'history-player@example.test',
            'hash' => password_hash($userPassword, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
    $db->exec('INSERT INTO profiles (account_id, stars, diamonds)
        VALUES (63011, 4321, 987)');
    $db->prepare('INSERT INTO songs
        (id, name, author_id, author_name, size, download_url, is_verified)
        VALUES (63013, :name, 1, :artist, 1.2, :url, 1)')->execute([
            'name' => 'Original Upgrade CI Music',
            'artist' => 'MuchoCore CI',
            'url' => 'https://example.test/upgrade-original.mp3',
        ]);
    $levelBytes = "kA11,1,2,3;1,0,2,3;UPGRADE\0level";
    $db->prepare('INSERT INTO levels
        (level_id, account_id, name, level_data, song_id, likes)
        VALUES (63012, 63011, :name, :data, 63013, -2)')->execute([
            'name' => 'Historical GD Level',
            'data' => $levelBytes,
        ]);
    $adminPassword = 'historical-ci-owner';
    $db->prepare('INSERT INTO admin_users
        (username, password_hash, role, password_must_set)
        VALUES (:name, :hash, :role, 0)')->execute([
            'name' => 'HistoryOwner',
            'hash' => password_hash($adminPassword, PASSWORD_BCRYPT, ['cost' => 4]),
            'role' => 'owner',
        ]);

    $cloudBytes = "old encrypted save\0GD 2.209 Привет\n" . random_bytes(96);
    $cloud = new CloudSaveRepository($db);
    $check($cloud->save(63011, $cloudBytes) === 1 &&
        $cloud->load(63011) === $cloudBytes,
        'historical encrypted Cloud Save round-trips before upgrade');

    $queries = [
        'account' => 'SELECT account_id, username, email, password_hash FROM accounts WHERE account_id=63011',
        'profile' => 'SELECT account_id, stars, diamonds FROM profiles WHERE account_id=63011',
        'level' => 'SELECT level_id, account_id, name, level_data, song_id, likes FROM levels WHERE level_id=63012',
        'song' => 'SELECT id, name, author_name, download_url, is_verified FROM songs WHERE id=63013',
        'admin' => "SELECT username, role, password_hash, password_must_set FROM admin_users WHERE username='HistoryOwner'",
    ];
    $before = [];
    foreach ($queries as $name => $sql) {
        $before[$name] = $db->query($sql)->fetch();
        $check(is_array($before[$name]), 'old ' . $name . ' fixture is present');
    }
    $fingerprint = hash_file('sha256', $keyPath);

    ob_start();
    try {
        (new Migrator($db, $root . '/database/migrations'))->migrate();
    } finally {
        ob_end_clean();
    }
    $newCount = (int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $check($newCount > $oldCount, 'new PHP8 migrations applied on existing data');
    $check((int) $db->query("SELECT COUNT(*) FROM schema_migrations
        WHERE version='20261110_001_gd2209_user_info'")->fetchColumn() === 1,
        'new GD 2.209 user info migration recorded');

    foreach ($queries as $name => $sql) {
        $check($db->query($sql)->fetch() === $before[$name],
            $name . ' unchanged after migration');
    }
    $check(password_verify($userPassword, $before['account']['password_hash']) &&
        password_verify($adminPassword, $before['admin']['password_hash']) &&
        $before['admin']['role'] === 'owner',
        'player credentials and owner role remain usable');
    $check((new CloudSaveRepository($db))->load(63011) === $cloudBytes &&
        hash_file('sha256', $keyPath) === $fingerprint,
        'old encrypted Cloud Save and exact key survive upgrade');

    ob_start();
    try {
        (new Migrator($db, $root . '/database/migrations'))->migrate();
    } finally {
        ob_end_clean();
    }
    $check((int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()
        === $newCount, 'repeated upgraded migration is idempotent');
    $check($db->query($queries['level'])->fetch() === $before['level'],
        'level remains untouched on repeat migration');

    echo "MUCHOCORE_PHP8_HISTORICAL_SCHEMA_UPGRADE_OK\n";
} finally {
    $db = null;
    if ($created && $server instanceof PDO) {
        $server->exec('DROP DATABASE IF EXISTS ' . $dbName);
    }
    if ($originalKey === false) {
        putenv('MUCHO_CLOUDSAVE_KEY_FILE');
    } else {
        putenv('MUCHO_CLOUDSAVE_KEY_FILE=' . $originalKey);
    }
    if ($originalEnvKey === null) {
        unset($_ENV['MUCHO_CLOUDSAVE_KEY_FILE']);
    } else {
        $_ENV['MUCHO_CLOUDSAVE_KEY_FILE'] = $originalEnvKey;
    }
    if (is_file($keyPath)) {
        unlink($keyPath);
    }
}
