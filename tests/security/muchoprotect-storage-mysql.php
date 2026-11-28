<?php
declare(strict_types=1);

use MuchoCore\Http\Request;
use MuchoCore\Security\DatabasePenaltyStore;
use MuchoCore\Security\DatabaseRateLimiter;
use MuchoCore\Security\MuchoProtect;

$root = dirname(__DIR__, 2);
$compiled = in_array('--compiled', $argv, true);
if ($compiled) {
    require $root . '/compat/php7/domain.php';
} else {
    require $root . '/vendor/autoload.php';
}

function checkProtectStorage(bool $ok, string $message)
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$host = getenv('TEST_DB_HOST');
$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
$password = getenv('TEST_DB_ROOT_PASSWORD');
if ($host !== '127.0.0.1' || $password === false || $password === '') {
    throw new RuntimeException('Requires a disposable local MariaDB instance.');
}
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$dsn = 'mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4';
$server = new PDO($dsn, 'root', $password, $options);
$database = 'mc_guard_test_' . bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE ' . $database);
$audit = sys_get_temp_dir() . '/' . $database;
$_ENV['MUCHO_PROTECT'] = '1';
$_ENV['MUCHO_PROTECT_FAIL_OPEN'] = '1';
$_ENV['MUCHO_PROTECT_AUDIT_DIR'] = $audit;

try {
    $db = new PDO($dsn . ';dbname=' . $database, 'root', $password, $options);
    $path = $root . '/database/migrations/20260927_001_security_storage.php';
    $migration = require ($compiled ? mucho7_compiled_include($path) : $path);
    $migration->up($db);
    $rate = new DatabaseRateLimiter($db);
    $penalties = new DatabasePenaltyStore($db);
    checkProtectStorage($rate->allow('available', 2, 60), 'Available storage accepts first request');
    checkProtectStorage($rate->allow('available', 2, 60), 'Available storage accepts second request');
    checkProtectStorage(!$rate->allow('available', 2, 60), 'Fail-open must enforce quotas while storage works');
    $age = $db->prepare('UPDATE mucho_security_rate_limits SET window_start=UNIX_TIMESTAMP()-61 WHERE bucket_key=?');
    $age->execute([hash('sha256', 'available')]);
    checkProtectStorage($rate->allowStrict('available', 1, 60), 'Expired rate window resets to one');
    checkProtectStorage(!$rate->allowStrict('available', 1, 60), 'Rate window enforces its next request');

    $db->beginTransaction();
    checkProtectStorage(!$rate->allowStrict('inside-transaction', 1, 60), 'Limiter starts no nested transaction');
    checkProtectStorage($penalties->penalize('inside-transaction')['seconds'] === 0 && $db->inTransaction(),
        'Security storage must preserve the caller transaction');
    $db->rollBack();

    $ipKey = 'ip:192.0.2.15:endpoint:/getgjusers20';
    $globalKey = 'ip:192.0.2.15:global';
    $penalties->penalize($globalKey);
    $penalties->penalize($ipKey);
    $states = $penalties->statuses([$globalKey, $ipKey, 'missing']);
    checkProtectStorage(count($states) === 2 && $states[$globalKey]['active'] && $states[$ipKey]['active'],
        'Batched read returns all active records');
    $db->exec('UPDATE mucho_security_penalties SET updated_at=FROM_UNIXTIME(UNIX_TIMESTAMP()-120)');
    checkProtectStorage($penalties->cleanup(60) === 0, 'Cleanup preserves active penalties');
    $request = new Request('POST', '/getGJUserSearch.php', [], [], ['REMOTE_ADDR' => '192.0.2.15']);
    $guard = new MuchoProtect($rate, $penalties);
    checkProtectStorage($guard->inspect($request, $request->path)['reason'] === 'temporary_penalty',
        'Batched penalties preserve ordering and the user-search budget');

    $db->exec('UPDATE mucho_security_penalties SET expires_at=0,updated_at=FROM_UNIXTIME(UNIX_TIMESTAMP()-120)');
    checkProtectStorage($penalties->cleanup(60, 1) === 1, 'Cleanup is bounded and removes expired penalties');
    $db->exec('DROP TABLE mucho_security_rate_limits');
    checkProtectStorage(!$rate->allowStrict('unavailable', 1, 60), 'Strict mode rejects a storage failure');
    checkProtectStorage($rate->allow('unavailable', 1, 60), 'Fail-open only permits storage failures');
    echo "MUCHOCORE_MUCHOPROTECT_DB_OK\n";
} finally {
    $server->exec('DROP DATABASE ' . $database);
    if (is_file($audit . '/events.ndjson')) {
        unlink($audit . '/events.ndjson');
        rmdir($audit);
    }
}
