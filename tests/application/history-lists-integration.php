<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'MuchoCore\\')) {
        require dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, 10)) . '.php';
    }
});

use MuchoCore\Comment\CommentHistoryRepository;
use MuchoCore\Comment\CommentHistoryService;
use MuchoCore\LevelList\LevelListRepository;
use MuchoCore\LevelList\LevelListService;
use MuchoCore\V71\AuthService;

function historyCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}

$host = getenv('TEST_DB_HOST');
$port = getenv('TEST_DB_PORT') ?: '3306';
$password = getenv('TEST_DB_ROOT_PASSWORD');
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('Requires an isolated local/CI database, never production.');
}
$connect = static fn(?string $db): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port . ($db === null ? '' : ';dbname=' . $db) . ';charset=utf8mb4',
    'root', $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$name = 'mc_history_test_' . bin2hex(random_bytes(6));
$server = $connect(null);
$post = $_POST;
try {
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4');
    $db = $connect($name);
    $db->exec('CREATE TABLE accounts (
        account_id BIGINT PRIMARY KEY, username VARCHAR(20), gjp2_hash VARCHAR(64),
        is_active TINYINT DEFAULT 1, is_banned TINYINT DEFAULT 0) ENGINE=InnoDB');
    $credential = str_repeat('a', 40);
    $q = $db->prepare('INSERT INTO accounts(account_id,username,gjp2_hash) VALUES (?,?,?)');
    foreach ([[1, 'Alice'], [501, 'Bob'], [2, 'NoProfile']] as [$id, $username]) {
        $q->execute([$id, $username, hash('sha256', $credential)]);
    }
    // Alice's player ID collides with Bob's account ID on purpose.
    $db->exec('CREATE TABLE profiles (account_id BIGINT PRIMARY KEY, user_id BIGINT UNIQUE,
        cube INT, icon_id INT, color1 INT, color2 INT, icon_type INT, special INT, glow INT) ENGINE=InnoDB');
    $db->exec('INSERT INTO profiles VALUES (1,501,42,7,11,12,2,1,0),(501,901,3,3,2,3,0,0,0)');
    $db->exec('CREATE TABLE levels (level_id BIGINT PRIMARY KEY, is_deleted TINYINT,
        is_unlisted TINYINT) ENGINE=InnoDB');
    $db->exec('INSERT INTO levels VALUES (10,0,0),(11,0,0),(12,1,0),(13,0,1)');
    $db->exec('CREATE TABLE comments (id BIGINT PRIMARY KEY, account_id BIGINT, level_id BIGINT,
        content VARCHAR(1024), likes INT, is_spam TINYINT DEFAULT 0, percent INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $q = $db->prepare('INSERT INTO comments(id,account_id,level_id,content,likes) VALUES (?,?,?,?,?)');
    foreach ([
        [100,1,10,'hello 😀',1], [101,1,11,'second',5], [102,1,10,'third',5],
        [103,1,12,'deleted',0], [104,1,13,'unlisted',0], [105,1,99,'missing level',0],
        [106,999,10,'missing account',0], [200,501,10,'Bob only',0], [300,2,10,'legacy',0],
    ] as $row) { $q->execute($row); }

    $repo = new CommentHistoryRepository($db);
    $service = new CommentHistoryService($repo);
    $result = $repo->byUserId(501, 0, 10, false);
    historyCheck($result['total'] === 3 && $result['visible'] === 3 &&
        array_column($result['rows'], 'commentID') === [102,101,100],
        'player ID selects its author, not the colliding account');
    historyCheck((int)$result['rows'][0]['userID'] === 501 &&
        (int)$result['rows'][0]['extID'] === 1 && $result['rows'][0]['userName'] === 'Alice',
        'wire player ID and account ID remain distinct');
    historyCheck((int)$result['rows'][0]['icon'] === 42 &&
        (int)$result['rows'][0]['color1'] === 11 && (int)$result['rows'][0]['color2'] === 12 &&
        (int)$result['rows'][0]['iconType'] === 2 && (int)$result['rows'][0]['special'] === 1,
        'history returns the actual author appearance');
    historyCheck($repo->byUserId(999, 0, 10, false)['total'] === 0,
        'orphaned accounts are excluded from counts and rows');
    historyCheck($repo->byUserId(1, 0, 10, false)['total'] === 0,
        'an account ID is not silently treated as a different player ID');
    $page = $repo->byUserId(501, 1, 1, true);
    historyCheck($page['total'] === 3 && $page['visible'] === 1 &&
        (int)$page['rows'][0]['commentID'] === 101,
        'likes ties, pagination and public-only counts stay consistent');

    $_POST = ['userID'=>'501', 'gameVersion'=>'22'];
    $wire = $service->get();
    historyCheck(str_contains($wire, '~3~501~') && str_contains($wire, ':1~Alice~7~1~9~42') &&
        substr_count($wire, '#') === 1 && str_ends_with($wire, '#3:0:3'),
        '2.2 history uses inline authors when binaryVersion is omitted');
    $_POST = ['userID'=>'501', 'gameVersion'=>'19', 'binaryVersion'=>'30'];
    historyCheck(str_contains($service->get(), '#501:Alice:1#3:0:3'),
        'older clients retain their separate user section');
    $_POST = ['accountID'=>'501', 'gameVersion'=>'22'];
    historyCheck(str_contains($service->get(), 'Bob only') && str_contains($service->get(), '~3~901~'),
        'explicit accountID fallback resolves that account only');
    $_POST = ['userID'=>'501', 'accountID'=>'501', 'gameVersion'=>'22'];
    historyCheck(!str_contains($service->get(), 'Bob only'), 'userID takes precedence over accountID');
    $_POST = ['userID'=>['501']];
    historyCheck($service->get() === '-1', 'malformed identities produce the protocol failure sentinel');
    $_POST = ['userID'=>'777'];
    historyCheck($service->get() === '-2', 'unknown players have no comments');

    $db->exec('CREATE TABLE friends (account_id BIGINT, friend_account_id BIGINT) ENGINE=InnoDB');
    $db->exec('INSERT INTO friends VALUES (1,2),(501,1),(1,501)');
    $lists = new LevelListRepository($db);
    $ids = $lists->friendAccountIds(1);
    sort($ids);
    historyCheck($ids === [1,2,501], 'native prepared friend query includes both directions without duplicates');
    historyCheck($lists->friendAccountIds(0) === [], 'invalid friendship owner has no friends');
    $migration = require dirname(__DIR__, 2) . '/database/migrations/20260924_003_v71_runtime_schema.php';
    $migration($db);
    $db->exec("INSERT INTO mucho_level_lists(account_id,list_name,level_ids,created_at,updated_at,unlisted)
        VALUES (1,'own','10',1,1,0),(2,'friend','10',2,2,0),(501,'incoming','11',3,3,0),
               (501,'hidden','11',4,4,1),(999,'stranger','10',5,5,0)");
    $_POST = ['type'=>'13', 'accountID'=>'1', 'gjp2'=>$credential];
    $listService = new LevelListService($lists, new AuthService($db));
    $wire = $listService->get();
    historyCheck(str_contains($wire, '2:friend:') && str_contains($wire, '2:incoming:') &&
        !str_contains($wire, '2:own:') && !str_contains($wire, '2:hidden:') &&
        !str_contains($wire, '2:stranger:') && str_contains($wire, '#2:0:10#'),
        'authenticated friends list search returns public friend lists only');
    historyCheck($lists->allLevelsExist([10,11]) && !$lists->allLevelsExist([10,12]) &&
        !$lists->allLevelsExist([10,99]) && !$lists->allLevelsExist([]),
        'list validation rejects deleted, missing and empty level selections');
    historyCheck($lists->allLevelsExist([13]), 'explicit unlisted level IDs retain supported list behavior');
    $before = (int)$db->query('SELECT COUNT(*) FROM mucho_level_lists')->fetchColumn();
    $_POST = ['secret'=>'Wmfd2893gb7', 'accountID'=>'1', 'gjp2'=>$credential,
              'listName'=>'Broken', 'listLevels'=>'10,12'];
    historyCheck($listService->upload() === '-6' &&
        (int)$db->query('SELECT COUNT(*) FROM mucho_level_lists')->fetchColumn() === $before,
        'rejected list upload leaves stored lists unchanged');

    $db->exec('ALTER TABLE profiles DROP COLUMN cube, DROP COLUMN special');
    $row = $repo->byUserId(501, 0, 1, false)['rows'][0];
    historyCheck((int)$row['icon'] === 7 && (int)$row['special'] === 0,
        'earlier profile schemas use icon_id and glow');
    $db->exec('DROP TABLE profiles');
    $row = $repo->byUserId(1, 0, 1, false)['rows'][0];
    historyCheck((int)$row['userID'] === 1 && (int)$row['icon'] === 1,
        'schemas without profiles retain legacy account identity defaults');
    $db->exec('DROP TABLE levels');
    $_POST = ['userID'=>'1'];
    historyCheck($service->get() === '-1', 'missing level visibility data fails closed');
    echo "HISTORY_LISTS_INTEGRATION_OK\n";
} finally {
    $_POST = $post;
    $server->exec('DROP DATABASE IF EXISTS ' . $name);
}
