<?php
/**
 * PHP 7.0-compatible integration: compiled native Cloud Save on disposable
 * 127.0.0.1 MariaDB. Never uses real GDPS accounts, database or key files.
 */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require_once $root . '/compat/php7/domain.php';

function cloudNativeCheck($ok, $what) {
    if (!$ok) {
        throw new RuntimeException('Compiled Cloud Save: ' . $what);
    }
}
$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === ''
    || !ctype_digit((string)$port)) {
    throw new RuntimeException('Cloud Save CI needs isolated local MariaDB.');
}
$connect = function ($dbName) use ($host, $password, $port) {
    $dsn = 'mysql:host=' . $host . ';port=' . $port
        . ($dbName === null ? '' : ';dbname=' . $dbName)
        . ';charset=utf8mb4';
    return new PDO($dsn, 'root', $password, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));
};
$name = 'mc_cloud_native_' . bin2hex(random_bytes(5));
$directory = sys_get_temp_dir() . '/mc-native-key-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot create isolated private key folder.');
}
$keyFile = $directory . '/cloudsave.key';
file_put_contents($keyFile, base64_encode(random_bytes(32)) . "\n");
chmod($keyFile, 0600);
$oldEnv = getenv('MUCHO_CLOUDSAVE_KEY_FILE');
$oldArray = isset($_ENV['MUCHO_CLOUDSAVE_KEY_FILE'])
    ? $_ENV['MUCHO_CLOUDSAVE_KEY_FILE'] : null;
putenv('MUCHO_CLOUDSAVE_KEY_FILE=' . $keyFile);
$_ENV['MUCHO_CLOUDSAVE_KEY_FILE'] = $keyFile;

$server = null;
try {
    $server = $connect(null);
    $server->exec('CREATE DATABASE ' . $name
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db = $connect($name);
    $db->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED PRIMARY KEY,
        username VARCHAR(64) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL DEFAULT \'\',
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE profiles (
        user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL UNIQUE,
        stars INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    $insert = $db->prepare('INSERT INTO accounts(
        account_id,username,password_hash,gjp2_hash
    ) VALUES(?,?,?,?)');
    $insert->execute(array(1, 'One',
        password_hash('password-one', PASSWORD_BCRYPT),
        password_hash(str_repeat('a', 40), PASSWORD_BCRYPT)));
    $insert->execute(array(2, 'Two',
        password_hash('password-two', PASSWORD_BCRYPT), ''));
    $db->exec('INSERT INTO profiles(account_id) VALUES(1),(2)');
    foreach (require $root .
        '/compat/php7/core/database/migrations/20260923_001_cloud_save.php'
        as $sql) {
        $db->exec($sql);
    }
    $repo = new \MuchoCore\CloudSave\CloudSaveRepository($db);
    $auth = new \MuchoCore\Account\AccountAuthenticator($db);
    $service = new \MuchoCore\CloudSave\CloudSaveService($db, $auth, $repo);
    $controller = new \MuchoCore\CloudSave\CloudSaveController($service);
    $history = function ($accountId) use ($db) {
        $query = $db->prepare(
            'SELECT revision FROM mucho_cloud_save_revisions
             WHERE account_id=? ORDER BY revision DESC'
        );
        $query->execute(array($accountId));
        return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    };
    $revision = function ($accountId) use ($db) {
        $query = $db->prepare(
            'SELECT revision FROM mucho_cloud_saves WHERE account_id=?'
        );
        $query->execute(array($accountId));
        return (int)$query->fetchColumn();
    };

    $first = "binary\0first\xff  ";
    cloudNativeCheck($repo->load(1) === null, 'missing save');
    cloudNativeCheck($repo->save(1, $first) === 1, 'first revision');
    cloudNativeCheck($repo->load(1) === $first, 'binary round trip');
    cloudNativeCheck($repo->save(1, $first) === 1
        && $history(1) === array(), 'duplicate never adds history');

    // The compiler must retain the canonical account-row lock. Locking
    // only a missing child save row cannot serialize first-time backups.
    $source = file_get_contents($root .
        '/compat/php7/core/src/CloudSave/CloudSaveRepository.php');
    cloudNativeCheck(strpos($source, 
        'SELECT account_id FROM accounts WHERE account_id=:account FOR UPDATE'
    ) !== false, 'parent lock absent in compiled PHP7');

    $failed = false;
    try {
        $repo->save(999, 'orphan');
    } catch (RuntimeException $e) {
        $failed = true;
    }
    cloudNativeCheck($failed && $repo->load(999) === null,
        'missing account cannot create orphan save');

    $second = 'Second;21;30;a;a';
    cloudNativeCheck($repo->save(1, $second) === 2
        && $history(1) === array(1), 'new content archives old revision');

    $stored = $db->query(
        'SELECT ciphertext,nonce,auth_tag,sha256,size_bytes
         FROM mucho_cloud_saves WHERE account_id=1'
    )->fetch(PDO::FETCH_ASSOC);
    $badTag = $stored['auth_tag'];
    $badTag[0] = chr(ord($badTag[0]) ^ 1);
    $db->prepare('UPDATE mucho_cloud_saves SET auth_tag=? WHERE account_id=1')
        ->execute(array($badTag));
    $failed = false;
    try {
        $repo->save(1, $second);
    } catch (RuntimeException $e) {
        $failed = true;
    }
    cloudNativeCheck($failed && $revision(1) === 2,
        'damaged ciphertext is not acknowledged as duplicate');
    $db->prepare('UPDATE mucho_cloud_saves SET auth_tag=? WHERE account_id=1')
        ->execute(array($stored['auth_tag']));

    // An imported account may carry 100+ historical revisions.
    // A single new backup must retain EXACTLY five newest old revisions.
    $db->exec('UPDATE mucho_cloud_saves SET revision=150 WHERE account_id=1');
    $bulk = $db->prepare('INSERT INTO mucho_cloud_save_revisions(
        account_id, revision, ciphertext, nonce, auth_tag, sha256, size_bytes
    ) VALUES(1,?,?,?,?,?,?)');
    for ($n = 10; $n <= 149; ++$n) {
        $bulk->execute(array($n, random_bytes(8), random_bytes(12),
            random_bytes(16), hash('sha256', 'legacy-' . $n), 8));
    }
    cloudNativeCheck(count($history(1)) > 100, 'large import fixture created');
    cloudNativeCheck($repo->save(1, 'Post-import') === 151
        && $history(1) === array(150,149,148,147,146),
        'one backup prunes all stale history beyond five');
    cloudNativeCheck($repo->load(1) === 'Post-import',
        'pruning never changes decrypted current save');
    cloudNativeCheck($repo->save(1, 'Post-import') === 151
        && $history(1) === array(150,149,148,147,146),
        'duplicate preserves pruned history');
    cloudNativeCheck($repo->save(2, 'another save') === 1
        && $repo->load(2) === 'another save'
        && $revision(1) === 151, 'accounts maintain independent revision states');

    $valid = array('accountID'=>'1', 'password'=>'password-one',
        'saveData'=>'From GD;21;30;a;a');
    cloudNativeCheck($service->backup($valid) === '1',
        'authenticated backup writes a new revision');
    cloudNativeCheck($service->sync(array('accountID'=>'1',
        'gjp2'=>str_repeat('a',40))) === 'From GD;21;30;a;a',
        'GD 2.209 GJP2 reads the same encrypted save');
    $countBefore = $revision(1);

    // An explicit empty Request must not fall back to an unrelated global
    // POST. The router has already parsed the current HTTP request.
    $savedPost = $_POST;
    $_POST = $valid;
    try {
        $empty = new \MuchoCore\Http\Request('POST',
            '/database/backupGJAccountNew.php',
            array(), array(), array());
        cloudNativeCheck($controller->backup($empty)->body === '-1'
            && $controller->sync($empty)->body === '-1'
            && $revision(1) === $countBefore,
            'empty Request cannot inherit unrelated global POST credentials');
    } finally {
        $_POST = $savedPost;
    }
    $real = new \MuchoCore\Http\Request('POST',
        '/database/syncGJAccountNew.php', array(),
        array('accountID'=>'1','password'=>'password-one'), array());
    cloudNativeCheck($controller->sync($real)->body === 'From GD;21;30;a;a',
        'real parsed Request preserves Cloud Save GD response');

    $original = file_get_contents($keyFile);
    try {
        file_put_contents($keyFile, base64_encode(random_bytes(32)) . "\n");
        $failed = false;
        try {
            $repo->load(1);
        } catch (RuntimeException $e) {
            $failed = true;
        }
        cloudNativeCheck($failed, 'wrong encryption key fails closed');
    } finally {
        file_put_contents($keyFile, $original);
    }
    cloudNativeCheck($repo->load(1) === 'From GD;21;30;a;a',
        'restored original key recovers save');
    echo "MUCHOCORE_PHP7_CLOUDSAVE_HISTORY_MYSQL_OK\n";
} finally {
    if ($server instanceof PDO) {
        $server->exec('DROP DATABASE IF EXISTS ' . $name);
    }
    if ($oldEnv === false) {
        putenv('MUCHO_CLOUDSAVE_KEY_FILE');
    } else {
        putenv('MUCHO_CLOUDSAVE_KEY_FILE=' . $oldEnv);
    }
    if ($oldArray === null) {
        unset($_ENV['MUCHO_CLOUDSAVE_KEY_FILE']);
    } else {
        $_ENV['MUCHO_CLOUDSAVE_KEY_FILE'] = $oldArray;
    }
    if (is_file($keyFile)) {
        unlink($keyFile);
    }
    rmdir($directory);
}
