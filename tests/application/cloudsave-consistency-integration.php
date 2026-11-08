<?php

declare(strict_types=1);

/**
 * PHP 8 Cloud Save integrity checks against disposable CI MariaDB.
 * No existing databases, accounts or encryption keys are read or modified.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\CloudSave\CloudSaveRepository;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('Cloud save integration requires isolated CI MariaDB.');
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
$check = static function (bool $ok, string $name): void {
    if (!$ok) {
        throw new RuntimeException('Cloud save: ' . $name);
    }
    echo 'PASS ' . $name . PHP_EOL;
};

$name = 'mc_cloud_it_' . bin2hex(random_bytes(5));
$directory = sys_get_temp_dir() . '/mc-cloud-it-' . bin2hex(random_bytes(5));
if (!mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot create test key directory.');
}
$keyFile = $directory . '/cloudsave.key';
file_put_contents($keyFile, base64_encode(random_bytes(32)) . PHP_EOL);
chmod($keyFile, 0600);

$originalEnvironment = getenv('MUCHO_CLOUDSAVE_KEY_FILE');
$originalEnvArray = $_ENV['MUCHO_CLOUDSAVE_KEY_FILE'] ?? null;
putenv('MUCHO_CLOUDSAVE_KEY_FILE=' . $keyFile);
$_ENV['MUCHO_CLOUDSAVE_KEY_FILE'] = $keyFile;

$server = null;
$db = null;
try {
    $server = $connect(null);
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db = $connect($name);
    $db->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
    ) ENGINE=InnoDB');
    $db->exec('INSERT INTO accounts(account_id) VALUES (1),(2)');
    foreach (require dirname(__DIR__, 2) . '/database/migrations/20260923_001_cloud_save.php' as $sql) {
        $db->exec($sql);
    }

    $repository = new CloudSaveRepository($db);
    $revision = static function (int $accountId) use ($db): int {
        $q = $db->prepare('SELECT revision FROM mucho_cloud_saves WHERE account_id=?');
        $q->execute([$accountId]);
        return (int)$q->fetchColumn();
    };
    $history = static function (int $accountId) use ($db): array {
        $q = $db->prepare('SELECT revision FROM mucho_cloud_save_revisions
            WHERE account_id=? ORDER BY revision DESC');
        $q->execute([$accountId]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    };

    $first = "test-save\0a\r\n \xFF";
    $second = "test-save\0b\nПривет GD 2.209";
    $third = 'test-save-c';

    $check($repository->load(1) === null, 'missing save is reported as empty');
    $check($repository->save(1, $first) === 1, 'first save creates revision 1');
    $check($revision(1) === 1 && $repository->load(1) === $first,
        'first backup round-trips binary data byte-for-byte');
    $check($repository->save(1, $first) === 1 && $history(1) === [],
        'duplicate backup does not create history');
    $check($repository->save(1, $second) === 2 && $history(1) === [1],
        'replacement backup creates exactly one historical revision');
    $check($repository->load(1) === $second,
        'latest backup decrypts without losing Unicode');

    // Read previous encrypted revision with the same key; never overwrite it.
    $q = $db->query('SELECT ciphertext,nonce,auth_tag,sha256,size_bytes
        FROM mucho_cloud_save_revisions WHERE account_id=1 AND revision=1');
    $old = $q->fetch(PDO::FETCH_ASSOC);
    $decrypt = new ReflectionMethod($repository, 'decrypt');
    $oldPlain = $decrypt->invoke(
        $repository, (string)$old['ciphertext'], (string)$old['nonce'], (string)$old['auth_tag']
    );
    $check($oldPlain === $first &&
        (int)$old['size_bytes'] === strlen($first) &&
        hash_equals((string)$old['sha256'], hash('sha256', $first)),
        'history preserves decryptable bytes and integrity metadata');

    // A legacy import might have far more than 100 history rows. Prune all
    // obsolete revisions in one backup instead of only a fixed 100-row page.
    $db->exec('UPDATE mucho_cloud_saves SET revision=130 WHERE account_id=1');
    $insert = $db->prepare('INSERT INTO mucho_cloud_save_revisions
        (account_id,revision,ciphertext,nonce,auth_tag,sha256,size_bytes)
        VALUES (1,?,?,?,?,?,?)');
    for ($n = 10; $n <= 129; $n++) {
        $insert->execute([
            $n,
            random_bytes(8),
            random_bytes(12),
            random_bytes(16),
            hash('sha256', 'historical-' . $n),
            8,
        ]);
    }
    $check(count($history(1)) > 100, 'legacy fixture exceeds 100 historical revisions');
    $check($repository->save(1, $third) === 131,
        'first backup after import keeps revision monotonic');
    $check($history(1) === [130,129,128,127,126],
        'one backup removes all stale history beyond five revisions');
    $check($repository->load(1) === $third,
        'history cleanup does not corrupt latest save');
    $check($repository->save(1, $third) === 131 &&
        $history(1) === [130,129,128,127,126],
        'duplicate backup keeps history unchanged after cleanup');

    $check($repository->save(2, 'other account') === 1 &&
        $repository->load(2) === 'other account',
        'another account has independent encrypted save history');
    $check($revision(1) === 131 && $revision(2) === 1,
        'saving to a different account does not change first account');

    $missingRejected = false;
    try {
        $repository->save(999, 'not an account');
    } catch (RuntimeException) {
        $missingRejected = true;
    }
    $check($missingRejected && $repository->load(999) === null,
        'missing account cannot create orphan save');

    echo "MUCHOCORE_CLOUDSAVE_CONSISTENCY_OK\n";
} finally {
    $db = null;
    if ($server instanceof PDO) {
        $server->exec('DROP DATABASE IF EXISTS ' . $name);
    }
    if ($originalEnvironment === false) {
        putenv('MUCHO_CLOUDSAVE_KEY_FILE');
    } else {
        putenv('MUCHO_CLOUDSAVE_KEY_FILE=' . $originalEnvironment);
    }
    if ($originalEnvArray === null) {
        unset($_ENV['MUCHO_CLOUDSAVE_KEY_FILE']);
    } else {
        $_ENV['MUCHO_CLOUDSAVE_KEY_FILE'] = $originalEnvArray;
    }
    if (is_file($keyFile)) {
        unlink($keyFile);
    }
    rmdir($directory);
}
