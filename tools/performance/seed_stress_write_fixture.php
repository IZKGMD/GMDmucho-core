<?php

declare(strict_types=1);

/**
 * Creates genuine GD2.209 GJP2 credentials and unique synthetic HTTP
 * comment requests for the ONLY authorized muchocore-stress database.
 * Credentials are stored in a local 0600 JSON fixture; never print them.
 */
use MuchoCore\Account\AccountService;
use MuchoCore\Database\Database;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli' || count($argv) !== 1
    || getenv('DB_NAME') !== 'mucho_stress'
    || is_file($root . '/.env')) {
    fwrite(STDERR, "REFUSED: synthetic stress container only.\n");
    exit(2);
}
$dir = $root . '/.stress-secrets';
$target = $dir . '/php8-write-fixture.json';
if (!is_dir($dir) || is_link($dir) || file_exists($target) || is_link($target)) {
    fwrite(STDERR, "REFUSED: private directory missing or fixture already exists.\n");
    exit(2);
}

$db = (new Database())->connection();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'mucho_stress') {
    throw new RuntimeException('Database identity mismatch.');
}
$check = $db->query(
    "SELECT username,email,password_hash
     FROM accounts WHERE account_id=1"
)->fetch(PDO::FETCH_ASSOC);
if (!$check || $check['username'] !== 'StressRobot'
    || $check['email'] !== 'stress-robot@example.invalid'
    || $check['password_hash'] !== 'disabled') {
    throw new RuntimeException('Synthetic StressRobot account not in expected initial state.');
}
$name = $db->query('SELECT name FROM levels WHERE level_id=1')->fetchColumn();
if ($name !== 'Synthetic Stress Level') {
    throw new RuntimeException('Synthetic level fixture is missing.');
}
$password = bin2hex(random_bytes(24));
$gjp2 = sha1($password . AccountService::GJP2_SALT);
$jobs = [];
for ($i = 0; $i < 64; $i++) {
    $message = 'Synthetic benchmark comment ' . bin2hex(random_bytes(8))
        . ' ' . sprintf('%02d', $i);
    $jobs[] = [
        'path' => '/database/uploadGJComment21.php',
        'form' => [
            'accountID' => '1',
            'gjp2' => $gjp2,
            'levelID' => '1',
            'comment' => strtr(base64_encode($message), '+/', '-_'),
            'gameVersion' => '22',
            'binaryVersion' => '51',
            'percent' => '0',
        ],
        'expected' => 'positive_id',
    ];
}
$json = json_encode(
    ['kind' => 'muchocore-synthetic-writes-v1', 'jobs' => $jobs],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
umask(0077);
$handle = fopen($target, 'x');
if ($handle === false) {
    throw new RuntimeException('Unable to create private fixture.');
}
try {
    if (fwrite($handle, $json . "\n") !== strlen($json) + 1) {
        throw new RuntimeException('Short write for synthetic fixture.');
    }
    fflush($handle);
} finally {
    fclose($handle);
}
chmod($target, 0600);

try {
    $q = $db->prepare(
        'UPDATE accounts SET password_hash=:password_hash, gjp2_hash=:gjp2_hash
         WHERE account_id=1 AND username=:username AND password_hash=:original'
    );
    $q->execute([
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'gjp2_hash' => password_hash($gjp2, PASSWORD_DEFAULT),
        'username' => 'StressRobot',
        'original' => 'disabled',
    ]);
    if ($q->rowCount() !== 1) {
        throw new RuntimeException('Synthetic account update was rejected.');
    }
} catch (Throwable $error) {
    @unlink($target);
    throw $error;
}
echo "SYNTHETIC_WRITE_FIXTURE_READY=64\n";
echo "SYNTHETIC_CREDENTIALS_STORED_PRIVATELY=YES\n";
