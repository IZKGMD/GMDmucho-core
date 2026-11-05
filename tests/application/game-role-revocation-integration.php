<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$compiled = getenv('MUCHO_TEST_COMPILED_DOMAIN') === '1';
require $root . ($compiled ? '/compat/php7/domain.php' : '/vendor/autoload.php');

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Database\Migrator;
use MuchoCore\Interaction\CommentRepository;
use MuchoCore\Interaction\CommentService;
use MuchoCore\Protocol\GdCommentEncoder;

function roleCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}

$host = getenv('TEST_DB_HOST');
$port = getenv('TEST_DB_PORT') ?: '3306';
$password = getenv('TEST_DB_ROOT_PASSWORD');
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('Requires isolated local/CI MariaDB, never an installation database.');
}
$db = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', 'root', $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$name = 'mc_role_revoke_' . bin2hex(random_bytes(6));
$server = null;
$router = null;
$log = null;
try {
    $db->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4');
    $db->exec('USE ' . $name);
    ob_start();
    try {
        (new Migrator($db, $root . '/database/migrations'))->migrate();
    } finally {
        ob_end_clean();
    }
    $credential = sha1('role-test-password' . 'mI29fmAnxgTs');
    $insert = $db->prepare('INSERT INTO accounts(account_id,username,email,password_hash,gjp2_hash,role_id,role)
        VALUES(:id,:name,:email,:password,:gjp,(SELECT id FROM roles WHERE code=:role),:legacy)');
    foreach ([1 => ['owner', 'user'], 2 => ['user', 'owner'], 3 => ['moderator', 'owner'], 4 => ['elder_moderator', 'user']] as $id => [$role, $legacy]) {
        $insert->execute([
            'id' => $id, 'name' => 'RoleTest' . $id, 'email' => 'role' . $id . '@example.test',
            'password' => password_hash('role-test-password', PASSWORD_BCRYPT, ['cost' => 4]),
            'gjp' => password_hash($credential, PASSWORD_BCRYPT, ['cost' => 4]),
            'role' => $role, 'legacy' => $legacy,
        ]);
        $db->exec('INSERT INTO profiles(account_id) VALUES(' . $id . ')');
    }
    $db->exec("INSERT INTO levels(level_id,account_id,name,level_data) VALUES(1,1,'Revocation test','test-data')");
    $comments = new CommentService(new CommentRepository($db), new AccountAuthenticator($db), new GdCommentEncoder(), $db);
    if ($compiled) {
        roleCheck(str_contains((new ReflectionClass($comments))->getFileName(), '/compat/php7/core/'), 'exercise the compiled PHP7 comment service');
    }
    $command = static function (int $id, string $body) use ($comments, $credential): string {
        return $comments->uploadLevelComment(1, $id, $credential, base64_encode($body), 0, 22);
    };
    $state = static fn(): array => $db->query('SELECT stars,featured,is_deleted FROM levels WHERE level_id=1')->fetch();
    $before = $state();
    $command(2, '!feature');
    roleCheck($state() === $before, 'canonical player role overrides stale legacy owner privileges');
    $command(3, '!rate hard 5');
    roleCheck($state() === $before, 'canonical moderator cannot inherit legacy owner direct-rating rights');
    $command(4, '!rate hard 5');
    roleCheck((int)$state()['stars'] === 5, 'canonical elder role works when legacy role says user');
    $command(1, '!rate hard 6');
    roleCheck((int)$state()['stars'] === 6, 'canonical owner can still apply direct ratings');
    $db->exec("INSERT INTO roles(code,name,priority) VALUES('unknown_test_role','Unknown',0)");
    $db->exec("UPDATE accounts SET role_id=(SELECT id FROM roles WHERE code='unknown_test_role') WHERE account_id=2");
    $before = $state();
    $command(2, '!delete');
    roleCheck($state() === $before, 'unknown canonical roles fail closed instead of inheriting legacy privileges');
    // Simulate an old account without a matching canonical role row. This is
    // restricted to this disposable schema and restored immediately below.
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        $db->exec('UPDATE accounts SET role_id=65000 WHERE account_id=2');
    } finally {
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    $command(2, '!feature');
    roleCheck((int)$state()['featured'] === 1, 'legacy-only installs retain their explicit compatibility fallback');
    $db->exec("UPDATE accounts SET role_id=(SELECT id FROM roles WHERE code='owner'),role='owner' WHERE account_id=2");
    roleCheck((int)$db->query('SELECT COUNT(*) FROM comments')->fetchColumn() === 0, 'command attempts never become public comments');

    if (!$compiled) {
        $db->exec("INSERT INTO admin_roles(code,name,is_system) VALUES('role_test_manager','Role manager',0)");
        $db->exec("INSERT INTO admin_role_permissions(role_id,permission) SELECT id,'players.manage' FROM admin_roles WHERE code='role_test_manager'");
        $tokens = [];
        foreach (['OwnerAdmin' => 'owner', 'LimitedAdmin' => 'role_test_manager'] as $username => $role) {
            $query = $db->prepare('INSERT INTO admin_users(username,password_hash,role) VALUES(?,?,?)');
            $query->execute([$username, password_hash('test-admin-password', PASSWORD_BCRYPT, ['cost' => 4]), $role]);
            $id = (int)$db->lastInsertId();
            $tokens[$username] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $db->prepare("INSERT INTO mucho_admin_client_tokens(admin_user_id,token_hash,expires_at) VALUES(?,?,NOW()+INTERVAL 1 HOUR)")
                ->execute([$id, hash('sha256', $tokens[$username])]);
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException('Cannot allocate local HTTP test port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $router = tempnam(sys_get_temp_dir(), 'mc-role-router-');
        $log = tempnam(sys_get_temp_dir(), 'mc-role-http-');
        file_put_contents($router, '<?php require ' . var_export($root . '/vendor/autoload.php', true) . ';'
            . 'if (\MuchoCore\Core\Environment::get("DB_NAME") !== ' . var_export($name, true)
            . ' || \MuchoCore\Core\Environment::get("DB_HOST") !== "127.0.0.1"'
            . ' || \MuchoCore\Core\Environment::get("DB_PORT") !== ' . var_export($port, true) . ')'
            . '{http_response_code(503);exit("Isolated test configuration required.");}'
            . 'require ' . var_export($root . '/public/api/v2/admin-client.php', true) . ';');
        $environment = array_merge(getenv(), ['DB_HOST' => $host, 'DB_PORT' => $port, 'DB_NAME' => $name, 'DB_USER' => 'root', 'DB_PASS' => $password]);
        $server = proc_open([PHP_BINARY, '-S', $address, $router], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $environment);
        if (!is_resource($server)) {
            throw new RuntimeException('Cannot start local API test server.');
        }
        fclose($pipes[0]);
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if ($probe !== false) { fclose($probe); $ready = true; break; }
            usleep(20000);
        }
        roleCheck($ready, 'local administrator API server starts');
        $call = static function (string $actor, array $input) use ($address, $tokens): array {
            $context = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 5, 'ignore_errors' => true,
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $tokens[$actor] . "\r\n",
                'content' => json_encode($input, JSON_THROW_ON_ERROR)]]);
            $body = file_get_contents('http://' . $address . '/api/v2/admin-client.php', false, $context);
            preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $status);
            return [(int)($status[1] ?? 0), json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR)];
        };
        [$status, $body] = $call('LimitedAdmin', ['action' => 'players.set_role', 'account_id' => 1, 'role' => 'user']);
        roleCheck($status === 403 && $body['error'] === 'forbidden', 'limited administrator cannot demote a game owner');
        [$status] = $call('LimitedAdmin', ['action' => 'players.set_ban', 'account_id' => 1, 'banned' => true]);
        roleCheck($status === 403, 'limited administrator cannot ban a game owner');
        $db->exec("UPDATE accounts SET role_id=(SELECT id FROM roles WHERE code='elder_moderator'),role='owner' WHERE account_id=2");
        [$status] = $call('LimitedAdmin', ['action' => 'players.set_role', 'account_id' => 2, 'role' => 'user']);
        $role = $db->query('SELECT a.role,r.code FROM accounts a JOIN roles r ON r.id=a.role_id WHERE account_id=2')->fetch();
        roleCheck($status === 200 && $role['role'] === 'user' && $role['code'] === 'user', 'API demotion synchronizes canonical and legacy roles');
        $before = $state(); $command(2, '!delete');
        roleCheck($state() === $before, 'API-demoted player cannot use moderator commands');
        [$status] = $call('LimitedAdmin', ['action' => 'players.set_role', 'account_id' => 2, 'role' => 'owner']);
        roleCheck($status === 403, 'limited administrator cannot promote a game owner');
        $auditCount = (int)$db->query('SELECT COUNT(*) FROM mucho_admin_client_audit')->fetchColumn();
        $db->exec("CREATE TRIGGER role_test_audit_failure BEFORE INSERT ON mucho_admin_client_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test audit failure'");
        try {
            [$status, $body] = $call('OwnerAdmin', ['action' => 'players.set_role', 'account_id' => 2, 'role' => 'owner']);
            $role = $db->query('SELECT a.role,r.code FROM accounts a JOIN roles r ON r.id=a.role_id WHERE account_id=2')->fetch();
            roleCheck($status === 500 && $body['error'] === 'server_error' && $role['role'] === 'user' && $role['code'] === 'user', 'failed role audit rolls back both role fields');
            [$status] = $call('OwnerAdmin', ['action' => 'players.set_ban', 'account_id' => 2, 'banned' => true]);
            roleCheck($status === 500 && (int)$db->query('SELECT is_banned FROM accounts WHERE account_id=2')->fetchColumn() === 0, 'failed ban audit rolls back the ban');
        } finally {
            $db->exec('DROP TRIGGER role_test_audit_failure');
        }
        roleCheck((int)$db->query('SELECT COUNT(*) FROM mucho_admin_client_audit')->fetchColumn() === $auditCount, 'failed changes leave no successful audit records');
        [$status] = $call('OwnerAdmin', ['action' => 'players.set_role', 'account_id' => 1, 'role' => 'elder_moderator']);
        roleCheck($status === 200, 'owner administrator may deliberately reassign an owner game role');
        foreach ([999999, 999999] as $index => $id) {
            [$status] = $call('OwnerAdmin', ['action' => $index === 0 ? 'players.set_role' : 'players.set_ban', 'account_id' => $id, 'role' => 'user', 'banned' => true]);
            roleCheck($status === 404, 'missing target fails without partial changes');
        }
        // Hold the account row while an API request starts, then promote that
        // account before releasing it. Authorization must inspect the locked,
        // current role rather than mutate using a pre-lock role snapshot.
        foreach (['players.set_ban', 'players.set_role'] as $action) {
            $db->exec("UPDATE accounts SET role_id=(SELECT id FROM roles WHERE code='elder_moderator'),role='elder_moderator',is_banned=0 WHERE account_id=2");
            $db->beginTransaction();
            $db->query('SELECT account_id FROM accounts WHERE account_id=2 FOR UPDATE')->fetch();
            $pending = stream_socket_client('tcp://' . $address, $errno, $error, 5);
            if ($pending === false) { throw new RuntimeException('Cannot start concurrent API request.'); }
            try {
                stream_set_timeout($pending, 5);
                $json = json_encode(['action' => $action, 'account_id' => 2, 'role' => 'user', 'banned' => true], JSON_THROW_ON_ERROR);
                fwrite($pending, "POST /api/v2/admin-client.php HTTP/1.1\r\nHost: " . $address . "\r\nConnection: close\r\nContent-Type: application/json\r\nAuthorization: Bearer " . $tokens['LimitedAdmin'] . "\r\nContent-Length: " . strlen($json) . "\r\n\r\n" . $json);
                fflush($pending);
                $waiting = false;
                for ($attempt = 0; $attempt < 20; $attempt++) {
                    $waiting = (int)$db->query('SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX t ON t.trx_id=w.blocking_trx_id WHERE t.trx_mysql_thread_id=CONNECTION_ID()')->fetchColumn() > 0;
                    if ($waiting) { break; }
                    // InnoDB metadata snapshots are briefly cached. Let the
                    // snapshot expire instead of polling it continuously.
                    usleep(150000);
                }
                roleCheck($waiting, $action . ' waits for the target account lock');
                $db->exec("UPDATE accounts SET role_id=(SELECT id FROM roles WHERE code='owner'),role='owner' WHERE account_id=2");
                $db->commit();
                $response = stream_get_contents($pending);
                roleCheck(is_string($response) && preg_match('~^HTTP/1\.[01] 403 ~', $response) === 1, $action . ' rejects a target promoted concurrently to owner');
                $role = $db->query('SELECT a.role,r.code,a.is_banned FROM accounts a JOIN roles r ON r.id=a.role_id WHERE account_id=2')->fetch();
                roleCheck($role['code'] === 'owner' && $role['role'] === 'owner' && (int)$role['is_banned'] === 0, 'concurrent owner promotion survives the rejected mutation');
            } finally {
                if ($db->inTransaction()) { $db->rollBack(); }
                fclose($pending);
            }
        }
    }
    roleCheck(!$db->inTransaction(), 'no test connection transaction is left open');
    echo "MUCHOCORE_GAME_ROLE_REVOCATION_OK\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($router !== null && is_file($router)) { unlink($router); }
    if ($log !== null && is_file($log)) { unlink($log); }
    if ($db->inTransaction()) { $db->rollBack(); }
    $db->exec('DROP DATABASE IF EXISTS ' . $name);
}
