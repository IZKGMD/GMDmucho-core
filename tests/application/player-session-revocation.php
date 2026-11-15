<?php
declare(strict_types=1);

use MuchoCore\Account\PlayerSession;

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'MuchoCore\\')) {
        require $root . '/src/' . str_replace('\\', '/', substr($class, 10)) . '.php';
    }
});

// Execute the actual entry-point guards without booting/rendering the pages.
foreach ([['index.php', 'pdAccountFromSession', 'pdFormatNumber'],
          ['clans.php', 'pcAccount', 'pcUsableAccount']] as [$page, $guard, $next]) {
    $source = file_get_contents($root . '/public/dashboard/' . $page);
    $start = strpos($source, 'function ' . $guard . '(');
    $end = strpos($source, 'function ' . $next . '(', $start);
    eval(substr($source, $start, $end - $start));
}

function playerSessionCheck(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo 'PASS ' . $label . PHP_EOL;
}

$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE accounts (account_id INTEGER PRIMARY KEY, username TEXT,
    password_hash TEXT, gjp2_hash TEXT, is_active INTEGER, is_banned INTEGER)');
$db->prepare('INSERT INTO accounts VALUES (1,?,?,?,?,?)')->execute([
    'Alice', password_hash('fixture-password', PASSWORD_DEFAULT),
    password_hash(str_repeat('a', 40), PASSWORD_DEFAULT), 1, 0,
]);
$account = $db->query('SELECT * FROM accounts')->fetch(PDO::FETCH_ASSOC);

foreach (['pdAccountFromSession', 'pcAccount'] as $guard) {
    $_SESSION = ['account' => ['id' => 1, 'username' => 'Alice'], 'csrf' => 'old'];
    playerSessionCheck($guard($db) === null, $guard . ' rejects unbound legacy sessions');

    $session = PlayerSession::create($account);
    $_SESSION = ['account' => $session, 'csrf' => 'old'];
    playerSessionCheck(($guard($db)['id'] ?? 0) === 1, $guard . ' accepts current login');

    foreach ([['is_banned', 1], ['is_active', 0],
              ['password_hash', 'changed-password-hash'], ['gjp2_hash', 'changed-gjp2-hash']] as [$column, $value]) {
        $db->prepare('UPDATE accounts SET ' . $column . '=? WHERE account_id=1')->execute([$value]);
        $_SESSION = ['account' => $session, 'csrf' => 'old'];
        playerSessionCheck($guard($db) === null && !isset($_SESSION['account'], $_SESSION['csrf']),
            $guard . ' revokes session after ' . $column . ' changes');
        $db->prepare('UPDATE accounts SET ' . $column . '=? WHERE account_id=1')->execute([$account[$column]]);
    }

    $db->exec("UPDATE accounts SET username='Renamed' WHERE account_id=1");
    $_SESSION = ['account' => $session];
    playerSessionCheck(($guard($db)['username'] ?? '') === 'Renamed', $guard . ' refreshes current username');
    $db->exec("UPDATE accounts SET username='Alice' WHERE account_id=1");
}

$db->exec('DELETE FROM accounts');
$_SESSION = ['account' => $session];
playerSessionCheck(pdAccountFromSession($db) === null, 'deleted accounts lose dashboard access');
echo "PLAYER_SESSION_REVOCATION_OK\n";
