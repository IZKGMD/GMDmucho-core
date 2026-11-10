<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Account\AccountController;
use MuchoCore\Account\AccountRepository;
use MuchoCore\Account\AccountService;
use MuchoCore\Http\Request;
use MuchoCore\Job\JobQueue;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('Account login integration requires isolated CI MariaDB.');
}

$connect = static fn(?string $database): PDO => new PDO(
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

$check = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('Account login integration: ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
};

$name = 'mc_account_login_it_' . bin2hex(random_bytes(5));
$server = $connect(null);
$db = null;

try {
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db = $connect($name);

    $db->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        username VARCHAR(20) NOT NULL UNIQUE,
        email VARCHAR(254) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL DEFAULT \'\',
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0,
        last_login_at DATETIME NULL
    ) ENGINE=InnoDB');

    $db->exec('CREATE TABLE profiles (
        user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL UNIQUE
    ) ENGINE=InnoDB');

    $db->exec('CREATE TABLE audit_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NULL,
        action VARCHAR(96) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        metadata LONGTEXT NOT NULL
    ) ENGINE=InnoDB');

    $insert = $db->prepare('INSERT INTO accounts
        (account_id,username,email,password_hash,gjp2_hash,is_active,is_banned)
        VALUES (:id,:username,:email,:password,:gjp2,:active,:banned)');

    $add = static function (
        int $id,
        string $username,
        string $plain,
        string $gjp2 = '',
        int $active = 1,
        int $banned = 0
    ) use ($insert): void {
        $insert->execute([
            'id' => $id,
            'username' => $username,
            'email' => 'login-' . $id . '@example.test',
            'password' => password_hash($plain, PASSWORD_DEFAULT),
            'gjp2' => $gjp2,
            'active' => $active,
            'banned' => $banned,
        ]);
    };

    $spacedPassword = '  exact GD password  ';
    $add(1, 'SpaceUser', $spacedPassword);

    $legacyPassword = 'legacy-password';
    // Fixed protocol vector: keep this independent from AccountService constants.
    $legacyGjp2 = '8083523cd70c670176dc042b189128b424822278';
    $add(2, 'LegacyUser', 'not-the-login-password', password_hash($legacyGjp2, PASSWORD_DEFAULT));

    $modernPassword = 'modern-gjp2-password';
    // Cvolton and MegaSa1nt use SHA1(password + "mI29fmAnxgTs").
    $modernGjp2 = '1a1f6a7cbec30838efba53807ddbaa098b41018d';
    $add(3, 'Gjp2User', 'not-the-login-password', password_hash($modernGjp2, PASSWORD_DEFAULT));

    $precomputedPassword = 'precomputed-password';
    $precomputedGjp2 = '05ac8cbc6cb3e11b69432adca3a8268b1c70753c';
    $add(4, 'PrecomputedUser', 'not-the-login-password', password_hash($precomputedGjp2, PASSWORD_DEFAULT));

    $add(5, 'BannedUser', 'banned-password', '', 1, 1);
    $add(6, 'InactiveUser', 'inactive-password', '', 0, 0);
    $add(7, 'BackfillFailureUser', 'backfill-failure-password');

    $db->exec(<<<'SQL'
CREATE TRIGGER fail_gjp2_backfill
BEFORE UPDATE ON accounts
FOR EACH ROW
BEGIN
    IF NEW.account_id = 7 AND NEW.gjp2_hash <> OLD.gjp2_hash THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'simulated GJP2 backfill failure';
    END IF;
END
SQL);

    $controller = new AccountController(new AccountService(
        $db,
        new AccountRepository($db),
        new JobQueue($db)
    ));

    $login = static function (
        string $username,
        string $plain = '',
        string $gjp2 = ''
    ) use ($controller) {
        return $controller->login(new Request(
            'POST',
            '/loginGJAccount22.php',
            [],
            [
                'userName' => $username,
                'password' => $plain,
                'gjp2' => $gjp2,
                'gameVersion' => '22',
                'binaryVersion' => '51',
            ],
            ['REMOTE_ADDR' => '192.0.2.10']
        ));
    };

    $plain = $login(' SpaceUser ', $spacedPassword);
    $check($plain->status === 200 && $plain->contentType === 'text/plain; charset=utf-8',
        'login returns a plain-text Geometry Dash response');
    $check(preg_match('/^1,[1-9][0-9]*$/D', $plain->body) === 1,
        'plain-password login returns accountID,userID');
    $profileId = (int)substr($plain->body, 2);
    $check((int)$db->query('SELECT COUNT(*) FROM profiles WHERE account_id=1')->fetchColumn() === 1,
        'login creates exactly one missing profile');

    // Fixed GJP2 vector for the exact password above, including its boundary spaces.
    $expectedSpaceGjp2 = '68b2b4dc0e64b4ad799c619044a1668fcffb54f4';
    $storedSpaceGjp2 = (string)$db->query(
        'SELECT gjp2_hash FROM accounts WHERE account_id=1'
    )->fetchColumn();
    $check($storedSpaceGjp2 !== '' && password_verify($expectedSpaceGjp2, $storedSpaceGjp2),
        'plain-password login backfills the native GJP2 credential');
    $authenticated = (new AccountAuthenticator($db))->authenticate(1, $expectedSpaceGjp2);
    $check((int)$authenticated['account_id'] === 1,
        'backfilled GJP2 authenticates subsequent GD requests');
    $check((int)$db->query('SELECT COUNT(*) FROM audit_logs WHERE account_id=1 AND action=\'account.login\'')->fetchColumn() === 1,
        'successful login audit is recorded');

    $legacy = $login('LegacyUser', $legacyPassword);
    $check(preg_match('/^2,[1-9][0-9]*$/D', $legacy->body) === 1,
        'legacy GJP2 salt remains accepted');

    $modern = $login('Gjp2User', '', $modernGjp2);
    $check(preg_match('/^3,[1-9][0-9]*$/D', $modern->body) === 1,
        'GJP2-only login is accepted when the legacy password field is empty');

    $precomputed = $login('PrecomputedUser', $precomputedGjp2);
    $check(preg_match('/^4,[1-9][0-9]*$/D', $precomputed->body) === 1,
        'precomputed 40-character GJP2 credential remains accepted');

    $check($login('SpaceUser', trim($spacedPassword))->body === '-1',
        'password boundary spaces are significant');
    $check($login('SpaceUser', 'wrong-password')->body === '-1',
        'wrong password is rejected with the protocol failure sentinel');
    $check($login('MissingUser', 'some-password')->body === '-1',
        'unknown username uses the same protocol failure sentinel');
    $check($login('BannedUser', 'banned-password')->body === '-1',
        'banned account is rejected');
    $check($login('InactiveUser', 'inactive-password')->body === '-1',
        'inactive account is rejected');
    $check($login('SpaceUser')->body === '-1',
        'empty credentials are rejected');

    $backfillFailure = $login('BackfillFailureUser', 'backfill-failure-password');
    $check(preg_match('/^7,[1-9][0-9]*$/D', $backfillFailure->body) === 1,
        'valid password login survives an unavailable GJP2 backfill write');
    $check((string)$db->query(
        'SELECT gjp2_hash FROM accounts WHERE account_id=7'
    )->fetchColumn() === '',
        'failed GJP2 backfill leaves the existing empty credential unchanged');

    // Post-authentication bookkeeping is best-effort. Removing these optional
    // stores must not turn a valid credential into a client-visible login error.
    $db->exec('ALTER TABLE accounts DROP COLUMN last_login_at');
    $db->exec('DROP TABLE audit_logs');
    $afterBookkeepingFailure = $login('SpaceUser', $spacedPassword);
    $check($afterBookkeepingFailure->body === $plain->body,
        'login remains successful when optional post-login bookkeeping fails');
    $gjp2AfterRepeat = (string)$db->query(
        'SELECT gjp2_hash FROM accounts WHERE account_id=1'
    )->fetchColumn();
    $check(hash_equals($storedSpaceGjp2, $gjp2AfterRepeat),
        'subsequent password login preserves the existing GJP2 hash');

    $check((int)$db->query('SELECT COUNT(*) FROM profiles WHERE account_id=1')->fetchColumn() === 1,
        'repeated login does not duplicate the account profile');
    $check($profileId > 0, 'profile identifier is positive and stable');

    echo "MUCHOCORE_ACCOUNT_LOGIN_INTEGRATION_OK\n";
} finally {
    $db = null;
    $server->exec('DROP DATABASE IF EXISTS ' . $name);
}
