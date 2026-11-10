<?php

declare(strict_types=1);

/**
 * Deterministic PHP 8 registration-race test on disposable MariaDB.
 * The PDO test shim simulates stale uniqueness prechecks while the actual
 * duplicate is enforced by the database. No timing-dependent child processes.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Account\AccountRepository;
use MuchoCore\Account\AccountService;
use MuchoCore\Job\JobQueue;

final class StaleAccountLookupStatement extends PDOStatement
{
    public function __construct() {}

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return false;
    }
}

final class RegistrationRacePdo extends PDO
{
    private int $staleLookups = 0;

    public function simulateBothPrechecksAsStale(): void
    {
        $this->staleLookups = 2;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->staleLookups > 0 &&
            str_contains($query, 'SELECT 1') &&
            str_contains($query, 'FROM accounts') &&
            (str_contains($query, 'WHERE username = :username') ||
                str_contains($query, 'WHERE email = :email'))) {
            $this->staleLookups--;
            return new StaleAccountLookupStatement();
        }

        return parent::prepare($query, $options);
    }
}

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('Registration integration requires isolated CI MariaDB.');
}

$connect = static fn(?string $database): RegistrationRacePdo => new RegistrationRacePdo(
    'mysql:host=' . $host . ';port=' . $port .
    ($database === null ? '' : ';dbname=' . $database) . ';charset=utf8mb4',
    'root',
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$check = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('Registration integrity: ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
};

$name = 'mc_registration_it_' . bin2hex(random_bytes(5));
$server = $connect(null);
$db = null;
try {
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db = $connect($name);
    foreach ([
        '001_auth.php',
        '002_profiles.php',
        '006_audit_log.php',
        '007_account_auth.php',
    ] as $migration) {
        foreach (require dirname(__DIR__, 2) . '/database/migrations/' . $migration as $sql) {
            $db->exec($sql);
        }
    }

    $service = new AccountService($db, new AccountRepository($db), new JobQueue($db));
    $check($service->register('Alpha', 'secure pass 1', 'alpha@example.test', '192.0.2.8') === '1',
        'valid account registration succeeds');
    $check($service->register('Alpha', 'other pass', 'other@example.test', '192.0.2.8') === '-2',
        'normal duplicate username maps to -2');
    $check($service->register('Beta', 'other pass', 'alpha@example.test', '192.0.2.8') === '-3',
        'normal duplicate email maps to -3');

    // A concurrent request may have checked both fields before the winner
    // committed. Bypass the advisory SELECTs but let MariaDB enforce indexes.
    $db->simulateBothPrechecksAsStale();
    $check($service->register('Alpha', 'other pass', 'another@example.test', '192.0.2.8') === '-2',
        'unique username collision after stale prechecks maps to -2');
    $check(!$db->inTransaction(), 'username collision always rolls back');

    $db->simulateBothPrechecksAsStale();
    $check($service->register('Beta', 'other pass', 'alpha@example.test', '192.0.2.8') === '-3',
        'unique email collision after stale prechecks maps to -3');
    $check(!$db->inTransaction(), 'email collision always rolls back');

    $check((int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn() === 1,
        'duplicate inserts do not create phantom accounts');
    $check((int)$db->query('SELECT COUNT(*) FROM profiles')->fetchColumn() === 1,
        'duplicate inserts do not create orphan profiles');
    $check((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='account.register'")->fetchColumn() === 1,
        'each successful registration has exactly one audit record');

    $check($service->register('Beta', 'secure pass 2', 'beta@example.test', '192.0.2.9') === '1',
        'new unique account still registers after collisions');
    $check($service->login('Alpha', 'invalid pass', '', '192.0.2.9') === '-1',
        'invalid password cannot log in');
    $check($service->login('Alpha', 'secure pass 1', '', '192.0.2.9') !== '-1',
        'valid password logs in');
    $gjp2 = sha1('secure pass 1' . AccountService::GJP2_SALT);
    $check($service->login('Alpha', '', $gjp2, '192.0.2.9') !== '-1',
        'GD-compatible GJP2 logs in');
    $db->exec("UPDATE accounts SET is_banned=1 WHERE username='Alpha'");
    $check($service->login('Alpha', 'secure pass 1', '', '192.0.2.9') === '-12',
        'banned account uses Geometry Dash ban response -12');
    $db->exec("UPDATE accounts SET is_banned=0,is_active=0 WHERE username='Alpha'");
    $check($service->login('Alpha', 'secure pass 1', '', '192.0.2.9') === '-1',
        'inactive account cannot log in');

    $exactPassword = '  secure GD password  ';
    $check($service->register(
        'Spaced', $exactPassword, 'spaced@example.test', '192.0.2.10'
    ) === '1', 'register a password containing boundary spaces');
    $check($service->login('Spaced', $exactPassword, '', '192.0.2.10') !== '-1',
        'login preserves boundary spaces after registration');
    $spacedId = (int)$db->query(
        "SELECT account_id FROM accounts WHERE username='Spaced'"
    )->fetchColumn();
    $auth = new AccountAuthenticator($db);
    $check((int)$auth->authenticate($spacedId, $exactPassword)['account_id'] === $spacedId,
        'authenticated GD endpoints accept the original password bytes');
    $strippedRejected = false;
    try {
        $auth->authenticate($spacedId, trim($exactPassword));
    } catch (RuntimeException $error) {
        $strippedRejected = $error->getMessage() === 'Unauthorized.';
    }
    $check($strippedRejected,
        'authenticated GD endpoints reject altered password bytes');

    $check($service->register('bad username', 'pw', 'unique@example.test', '192.0.2.9') === '-4',
        'username validation preserves GD response -4');
    $check($service->register('Unique', 'pw', 'not-email', '192.0.2.9') === '-1',
        'invalid email is refused');

    // A real infrastructure failure (unrelated to uniqueness) must still
    // roll back the account/profile transaction instead of returning success.
    $db->exec('RENAME TABLE audit_logs TO audit_logs_hidden');
    $auditUnavailable = false;
    try {
        $service->register('MissingAudit', 'pass', 'missing@example.test', '192.0.2.9');
    } catch (PDOException) {
        $auditUnavailable = true;
    }
    $check($auditUnavailable && !$db->inTransaction(),
        'unrelated database errors still fail closed after rollback');
    $check((int)$db->query("SELECT COUNT(*) FROM accounts WHERE username='MissingAudit'")->fetchColumn() === 0,
        'failed registration cannot leave a half-created account');

    echo "MUCHOCORE_PHP8_REGISTRATION_INTEGRITY_OK\n";
} finally {
    $db = null;
    $server->exec('DROP DATABASE IF EXISTS ' . $name);
}
