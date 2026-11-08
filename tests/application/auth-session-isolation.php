<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Account/AccountAuthenticator.php';
require dirname(__DIR__, 2) . '/src/Level/LevelAccess.php';
require dirname(__DIR__, 2) . '/src/CloudSave/CloudSaveRepository.php';
require dirname(__DIR__, 2) . '/src/CloudSave/CloudSaveService.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\CloudSave\CloudSaveRepository;
use MuchoCore\CloudSave\CloudSaveService;
use MuchoCore\Level\LevelAccess;

function assertAuthIsolation(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "FAIL auth-session-isolation requires pdo_sqlite\n");
    exit(1);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction(
    'UTC_TIMESTAMP',
    static fn(): string => '2026-10-07 06:30:00',
    0
);

$pdo->exec(
    'CREATE TABLE accounts (
        account_id INTEGER PRIMARY KEY,
        username TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        gjp2_hash TEXT NOT NULL DEFAULT "",
        is_active INTEGER NOT NULL DEFAULT 1,
        is_banned INTEGER NOT NULL DEFAULT 0
    )'
);
$pdo->exec(
    'CREATE TABLE profiles (
        user_id INTEGER PRIMARY KEY,
        account_id INTEGER NOT NULL UNIQUE
    )'
);
$pdo->exec(
    'CREATE TABLE mucho_auth_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        account_id INTEGER NOT NULL,
        ip_address TEXT NOT NULL,
        created_at TEXT NOT NULL,
        last_used_at TEXT NULL,
        expires_at TEXT NOT NULL
    )'
);

$password = 'correct-horse-battery-staple';
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$insertAccount = $pdo->prepare(
    'INSERT INTO accounts
        (account_id, username, password_hash, gjp2_hash, is_active, is_banned)
     VALUES
        (1, :username, :password_hash, "", 1, 0)'
);
$insertAccount->execute([
    'username' => 'shared-ip-owner',
    'password_hash' => $passwordHash,
]);

$pdo->exec(
    'INSERT INTO profiles (user_id, account_id)
     VALUES (101, 1)'
);
$pdo->exec(
    "INSERT INTO mucho_auth_sessions
        (account_id, ip_address, created_at, last_used_at, expires_at)
     VALUES
        (1, '203.0.113.42', '2026-10-07 06:00:00', NULL, '2026-10-07 07:30:00')"
);

$authenticator = new AccountAuthenticator($pdo);

$wrongRejected = false;
try {
    $authenticator->authenticate(
        1,
        'definitely-wrong-credential',
        '203.0.113.42'
    );
} catch (RuntimeException $e) {
    $wrongRejected = $e->getMessage() === 'Unauthorized.';
}

assertAuthIsolation(
    $wrongRejected,
    'active account+IP grant cannot replace a valid credential'
);

$emptyRejected = false;
try {
    $authenticator->authenticate(
        1,
        '',
        '203.0.113.42'
    );
} catch (RuntimeException $e) {
    $emptyRejected = $e->getMessage() === 'Unauthorized.';
}

assertAuthIsolation(
    $emptyRejected,
    'empty credential remains unauthorized even with an active IP grant'
);

$account = $authenticator->authenticate(
    1,
    $password,
    '203.0.113.42'
);

assertAuthIsolation(
    (int)($account['account_id'] ?? 0) === 1 &&
    (int)($account['user_id'] ?? 0) === 101,
    'valid credential authenticates the intended account on the shared IP'
);


// Registering a password with boundary spaces is allowed by AccountService.
// Authentication and all authenticated GD endpoints must use those exact
// bytes rather than silently changing the stored credential.
$spacedPassword = '  exact GD password  ';
$insertSpaced = $pdo->prepare(
    'INSERT INTO accounts
        (account_id,username,password_hash,gjp2_hash,is_active,is_banned)
     VALUES (2,:username,:hash,"",1,0)'
);
$insertSpaced->execute([
    'username' => 'space-protected',
    'hash' => password_hash($spacedPassword, PASSWORD_DEFAULT),
]);
$pdo->exec('INSERT INTO profiles(user_id,account_id) VALUES (202,2)');

assertAuthIsolation(
    (int)$authenticator->authenticate(2, $spacedPassword)['account_id'] === 2,
    'password with boundary spaces is authenticated without mutation'
);

$trimmedRejected = false;
try {
    $authenticator->authenticate(2, trim($spacedPassword));
} catch (RuntimeException $error) {
    $trimmedRejected = $error->getMessage() === 'Unauthorized.';
}
assertAuthIsolation(
    $trimmedRejected,
    'stripping password spaces changes secret and is rejected'
);

assertAuthIsolation(
    LevelAccess::canRead(
        $pdo,
        ['account_id'=>2,'is_deleted'=>0,'unlisted2'=>1],
        $authenticator,
        2,
        $spacedPassword
    ),
    'private level access honors the exact credential'
);

$cloudService = new CloudSaveService(
    $pdo,
    $authenticator,
    new CloudSaveRepository($pdo)
);
$cloudAuth = new ReflectionMethod($cloudService, 'authenticate');
assertAuthIsolation(
    $cloudAuth->invoke($cloudService, [
        'accountID' => 2,
        'password' => $spacedPassword,
    ]) === 2,
    'Cloud Save uses exact password bytes'
);

$cloudTrimmedRejected = false;
try {
    $cloudAuth->invoke($cloudService, [
        'accountID' => 2,
        'password' => trim($spacedPassword),
    ]);
} catch (RuntimeException $error) {
    $cloudTrimmedRejected = $error->getMessage() === 'Unauthorized.';
}
assertAuthIsolation(
    $cloudTrimmedRejected,
    'Cloud Save cannot authenticate with modified password bytes'
);

echo "MUCHOCORE_AUTH_SESSION_ISOLATION_OK\n";
