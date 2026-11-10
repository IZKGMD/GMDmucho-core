<?php
declare(strict_types=1);

/**
 * Disposable PHP8 disaster-recovery acceptance against the real MuchoCore
 * migrated schema. No installed GDPS database is read or written.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Backup\DatabaseBackupService;
use MuchoCore\Backup\DatabaseBackupVerifier;
use MuchoCore\CloudSave\CloudSaveRepository;
use MuchoCore\Database\Migrator;

$host = getenv('TEST_DB_HOST');
$port = getenv('TEST_DB_PORT');
$pass = getenv('TEST_DB_ROOT_PASSWORD');
if (getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('MUCHO_TEST_ALLOW_WRITES') !== '1' ||
    $host !== '127.0.0.1' || $port !== '3306' ||
    !is_string($pass) || $pass === '') {
    throw new RuntimeException('Refusing disaster recovery outside isolated CI MariaDB.');
}

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('Disaster recovery: ' . $message);
    }
    echo 'PASS ' . $message . PHP_EOL;
};

$suffix = bin2hex(random_bytes(6));
$sourceName = 'mc_dr_source_' . $suffix;
$targetName = 'mc_dr_target_' . $suffix;
$dir = sys_get_temp_dir() . '/mc-dr-' . $suffix;
if (!mkdir($dir, 0700, true)) {
    throw new RuntimeException('Cannot create isolated recovery directory.');
}
$originalEnv = getenv('MUCHO_CLOUDSAVE_KEY_FILE');
$originalArray = $_ENV['MUCHO_CLOUDSAVE_KEY_FILE'] ?? null;
$keyPath = $dir . '/cloudsave.key';
$recoveredKeyPath = $dir . '/recovered-cloudsave.key';
$keyBytes = random_bytes(32);
file_put_contents($keyPath, base64_encode($keyBytes) . PHP_EOL);
chmod($keyPath, 0600);
$setKey = static function (string $path): void {
    putenv('MUCHO_CLOUDSAVE_KEY_FILE=' . $path);
    $_ENV['MUCHO_CLOUDSAVE_KEY_FILE'] = $path;
};
$setKey($keyPath);
$pdo = static fn(?string $database): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port .
    ($database === null ? '' : ';dbname=' . $database) .
    ';charset=utf8mb4',
    'root', $pass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$rootDb = $pdo(null);
$source = null;
$target = null;
try {
    $rootDb->exec('CREATE DATABASE ' . $sourceName .
        ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $rootDb->exec('CREATE DATABASE ' . $targetName .
        ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $source = $pdo($sourceName);

    ob_start();
    try {
        (new Migrator($source, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
    } finally {
        ob_end_clean();
    }
    $migrations = (int)$source->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $check($migrations > 15, 'full current MuchoCore schema migrates on clean database');

    $loginPassword = 'disaster-recovery-test-password';
    $hashedLogin = password_hash($loginPassword, PASSWORD_BCRYPT, ['cost' => 4]);
    $source->prepare('INSERT INTO accounts
        (account_id,username,email,password_hash)
        VALUES (62011,:username,:email,:hash)')->execute([
            'username' => 'RecoveryUser',
            'email' => 'recovery-player@example.test',
            'hash' => $hashedLogin,
        ]);
    $source->exec('INSERT INTO profiles(account_id,stars,diamonds)
        VALUES(62011,12345,678)');
    $source->prepare('INSERT INTO songs
        (id,name,author_id,author_name,size,download_url,is_verified)
        VALUES(62013,:name,2,:artist,1.25,:download,1)')->execute([
            'name' => 'Recoverable Custom Song',
            'artist' => 'MuchoCore Test Artist',
            'download' => 'https://music.example.test/owned-original.mp3',
        ]);
    $levelData = "kA11,1,2,3;1,0,2,3;Original\0GeometryDash";
    $source->prepare('INSERT INTO levels
        (level_id,account_id,name,level_data,song_id,likes)
        VALUES(62012,62011,:name,:payload,62013,-7)')->execute([
            'name' => 'Recoverable Level',
            'payload' => $levelData,
        ]);
    $source->prepare("INSERT INTO admin_users
        (username,password_hash,role,password_must_set)
        VALUES('BackupOwner',:hash,'owner',0)")->execute([
            'hash' => password_hash('backup-owner-secret', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);

    $cloudData = "Original cloud save\0GD2.209 Привет\n" . random_bytes(128);
    $cloud = new CloudSaveRepository($source);
    $check($cloud->save(62011, $cloudData) === 1 &&
        $cloud->load(62011) === $cloudData,
        'game Cloud Save is genuinely encrypted and decryptable before backup');

    $expectedSource = [
        'account' => $source->query('SELECT account_id,username,email,password_hash
             FROM accounts WHERE account_id=62011')->fetch(),
        'profile' => $source->query('SELECT account_id,stars,diamonds
             FROM profiles WHERE account_id=62011')->fetch(),
        'level' => $source->query('SELECT level_id,account_id,name,level_data,song_id,likes
             FROM levels WHERE level_id=62012')->fetch(),
        'song' => $source->query('SELECT id,name,author_name,download_url,is_verified
             FROM songs WHERE id=62013')->fetch(),
        'admin' => $source->query("SELECT username,role,password_hash,password_must_set
             FROM admin_users WHERE username='BackupOwner'")->fetch(),
    ];
    foreach ($expectedSource as $row) {
        $check(is_array($row), 'required recovery row exists in migrated schema');
    }

    $backup = (new DatabaseBackupService($source, $dir))->create('php8-disaster-recovery');
    $verified = DatabaseBackupVerifier::verify($backup['file']);
    $check(hash_equals($backup['sha256'], $verified['sha256']),
        'SQL backup passes stream integrity verification');

    // SQL must not contain the plaintext encryption secret or plaintext save.
    $sql = file_get_contents('compress.zlib://' . $backup['file']);
    $check(is_string($sql) && !str_contains($sql, base64_encode($keyBytes)) &&
        !str_contains($sql, 'Original cloud save'),
        'database backup never embeds Cloud Save key or decrypted account save');
    unset($sql);

    $keyCopy = copy($keyPath, $recoveredKeyPath);
    chmod($recoveredKeyPath, 0600);
    $check($keyCopy && hash_file('sha256', $keyPath) ===
        hash_file('sha256', $recoveredKeyPath),
        'separately retained Cloud Save key preserves exact bytes');

    // Prove later mutations do not affect the captured backup snapshot.
    $source->exec("UPDATE levels SET name='Changed after backup' WHERE level_id=62012");
    $source->exec("UPDATE accounts SET is_banned=1 WHERE account_id=62011");

    $cnfPath = $dir . '/mysql.cnf';
    $quoted = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $pass) . '"';
    file_put_contents($cnfPath, "[client]\nuser=root\npassword={$quoted}\n");
    chmod($cnfPath, 0600);
    $process = proc_open(
        [
            'mariadb', '--defaults-extra-file=' . $cnfPath,
            '--host=' . $host, '--port=' . $port,
            '--default-character-set=utf8mb4', $targetName,
        ],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start isolated MariaDB restore client.');
    }
    $stream = gzopen($backup['file'], 'rb');
    if ($stream === false) {
        throw new RuntimeException('Cannot open verified backup stream.');
    }
    try {
        while (!gzeof($stream)) {
            $chunk = gzread($stream, 65536);
            if (!is_string($chunk)) {
                throw new RuntimeException('Backup compressed stream failed.');
            }
            while ($chunk !== '') {
                $written = fwrite($pipes[0], $chunk);
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Restore client closed its input unexpectedly.');
                }
                $chunk = substr($chunk, $written);
            }
        }
    } finally {
        gzclose($stream);
        fclose($pipes[0]);
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $check($exit === 0, 'MariaDB accepts the full restored production schema');
    unset($stdout, $stderr);

    $source = null;
    $rootDb->exec('DROP DATABASE ' . $sourceName);
    $target = $pdo($targetName);
    $after = [
        'account' => $target->query('SELECT account_id,username,email,password_hash
             FROM accounts WHERE account_id=62011')->fetch(),
        'profile' => $target->query('SELECT account_id,stars,diamonds
             FROM profiles WHERE account_id=62011')->fetch(),
        'level' => $target->query('SELECT level_id,account_id,name,level_data,song_id,likes
             FROM levels WHERE level_id=62012')->fetch(),
        'song' => $target->query('SELECT id,name,author_name,download_url,is_verified
             FROM songs WHERE id=62013')->fetch(),
        'admin' => $target->query("SELECT username,role,password_hash,password_must_set
             FROM admin_users WHERE username='BackupOwner'")->fetch(),
    ];
    foreach ($after as $name => $row) {
        $check($row === $expectedSource[$name],
            'restored ' . $name . ' matches original byte-for-byte');
    }
    $check(password_verify($loginPassword, $after['account']['password_hash']) &&
        password_verify('backup-owner-secret', $after['admin']['password_hash']) &&
        $after['admin']['role'] === 'owner',
        'restored account and admin retain their login credentials');
    $check((int)$target->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()
        === $migrations, 'all migration versions survive database recovery');
    $check((int)$target->query('SELECT is_banned FROM accounts WHERE account_id=62011')
        ->fetchColumn() === 0,
        'restored snapshot predates later account bans and level edits');

    // Environment::get intentionally caches its *path* for one PHP
    // request. A wrong-key test must change the actual disposable key file
    // (or run a separate process), not just putenv() in the same request.
    // Model a new installation with a regenerated key, then recover the
    // separately held original copy without ever touching the VPS.
    file_put_contents($keyPath, base64_encode(random_bytes(32)) . PHP_EOL);
    chmod($keyPath, 0600);
    $wrongRejected = false;
    try {
        (new CloudSaveRepository($target))->load(62011);
    } catch (RuntimeException) {
        $wrongRejected = true;
    }
    $check($wrongRejected, 'restore refuses an incorrect Cloud Save key');
    $check(copy($recoveredKeyPath, $keyPath),
        'original backup key can be restored to configured filesystem path');
    chmod($keyPath, 0600);
    $check((new CloudSaveRepository($target))->load(62011) === $cloudData,
        'restored encrypted Cloud Save decrypts with the original retained key');

    ob_start();
    try {
        (new Migrator($target, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
    } finally {
        ob_end_clean();
    }
    $check((int)$target->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()
        === $migrations, 'repeat migration after restore is idempotent');

    echo "MUCHOCORE_PHP8_DISASTER_RECOVERY_OK\n";
} finally {
    $source = null;
    $target = null;
    $rootDb->exec('DROP DATABASE IF EXISTS ' . $targetName);
    $rootDb->exec('DROP DATABASE IF EXISTS ' . $sourceName);
    if ($originalEnv === false) {
        putenv('MUCHO_CLOUDSAVE_KEY_FILE');
    } else {
        putenv('MUCHO_CLOUDSAVE_KEY_FILE=' . $originalEnv);
    }
    if ($originalArray === null) {
        unset($_ENV['MUCHO_CLOUDSAVE_KEY_FILE']);
    } else {
        $_ENV['MUCHO_CLOUDSAVE_KEY_FILE'] = $originalArray;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $file = $dir . '/' . $entry;
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($dir);
}
