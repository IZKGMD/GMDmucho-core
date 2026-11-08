<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Http\Request;
use MuchoCore\Interaction\LikeController;
use MuchoCore\Interaction\LikeRepository;
use MuchoCore\Interaction\LikeService;
use MuchoCore\Interaction\VoteIntegrityAuditor;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('Refusing vote integration outside isolated CI MariaDB.');
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

$check = static function (bool $ok, string $name): void {
    if (!$ok) {
        throw new RuntimeException('Like integrity: ' . $name);
    }
};
$database = 'mc_likes_it_' . bin2hex(random_bytes(5));
$admin = $connect(null);
$db = null;

try {
    $admin->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4');
    $db = $connect($database);
    foreach ([
        'CREATE TABLE accounts (
            account_id INT UNSIGNED PRIMARY KEY,
            username VARCHAR(20) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            gjp2_hash VARCHAR(255) NOT NULL,
            is_active TINYINT NOT NULL DEFAULT 1,
            is_banned TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB',
        'CREATE TABLE profiles (
            user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id INT UNSIGNED NOT NULL UNIQUE
        ) ENGINE=InnoDB',
        'CREATE TABLE levels (
            level_id INT UNSIGNED PRIMARY KEY,
            account_id INT UNSIGNED NOT NULL,
            likes INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB',
        'CREATE TABLE comments (
            id INT UNSIGNED PRIMARY KEY,
            account_id INT UNSIGNED NOT NULL,
            likes INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB',
        'CREATE TABLE account_comments (
            id INT UNSIGNED PRIMARY KEY,
            account_id INT UNSIGNED NOT NULL,
            likes INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB',
        'CREATE TABLE mucho_level_lists (
            list_id INT UNSIGNED PRIMARY KEY,
            account_id INT UNSIGNED NOT NULL,
            likes INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB',
        'CREATE TABLE likes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_id INT UNSIGNED NOT NULL,
            type TINYINT UNSIGNED NOT NULL,
            account_id INT UNSIGNED NOT NULL DEFAULT 0,
            ip VARCHAR(45) NOT NULL DEFAULT \'\',
            is_like TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_like_item_actor (item_id, type, account_id, ip)
        ) ENGINE=InnoDB',
    ] as $sql) {
        $db->exec($sql);
    }
    $account = $db->prepare(
        'INSERT INTO accounts (account_id,username,password_hash,gjp2_hash)
         VALUES (?,?,?,?)'
    );
    foreach ([1 => 'Author', 2 => 'Voter'] as $id => $name) {
        $account->execute([$id, $name, password_hash('pass-' . $id, PASSWORD_DEFAULT), '']);
        $db->prepare('INSERT INTO profiles (account_id) VALUES (?)')->execute([$id]);
    }
    $db->exec('INSERT INTO levels(level_id,account_id) VALUES (20,1)');
    $db->exec('INSERT INTO comments(id,account_id) VALUES (1,1)');
    $db->exec('INSERT INTO account_comments(id,account_id) VALUES (3,1)');
    $db->exec('INSERT INTO mucho_level_lists(list_id,account_id) VALUES (4,1)');

    $repo = new LikeRepository($db);
    $score = static fn(string $table, string $column, int $itemId): int =>
        (int)$db->query('SELECT likes FROM ' . $table . ' WHERE ' . $column .
            '=' . $itemId)->fetchColumn();

    $check(!$repo->addLike(1, 2, 1, true), 'cannot vote on own comment');
    $check($score('comments', 'id', 1) === 0, 'rejected self-vote leaves score at zero');
    $check(!$repo->addLike(999, 2, 2, true), 'cannot vote on deleted/missing comment');
    $check($repo->addLike(1, 2, 2, false), 'first dislike succeeds');
    $check($score('comments', 'id', 1) === -1, 'first dislike is -1');
    $check($repo->addLike(1, 2, 2, false), 'repeated dislike is idempotent success');
    $check($score('comments', 'id', 1) === -1, 'repeated dislike leaves score');
    $check($repo->addLike(1, 2, 2, true), 'switch to like succeeds');
    $check($score('comments', 'id', 1) === 1, '-1 to +1 changes score by +2');
    $check($repo->addLike(1, 2, 2, true), 'repeated like is idempotent success');
    $check($score('comments', 'id', 1) === 1, 'repeated like leaves score');
    $check($repo->addLike(1, 2, 2, false), 'switch back to dislike succeeds');
    $check($score('comments', 'id', 1) === -1, '+1 to -1 changes score by -2');
    $check((int)$db->query('SELECT COUNT(*) FROM likes WHERE item_id=1 AND type=2')->fetchColumn() === 1,
        'only one vote row per authenticated actor');

    // Account 0 votes use distinct IPs in the migrated uniqueness index.
    $check($repo->addLike(1, 2, 0, false, '127.0.0.2'), 'first anonymous actor');
    $check($repo->addLike(1, 2, 0, true, '127.0.0.3'), 'second anonymous actor');
    $check($score('comments', 'id', 1) === -1, 'anonymous +1 and -1 cancel');
    $check((int)$db->query('SELECT COUNT(*) FROM likes WHERE item_id=1 AND type=2')->fetchColumn() === 3,
        'anonymous actors do not overwrite other voters');

    $controller = new LikeController(new LikeService(
        $repo,
        new AccountAuthenticator($db)
    ));
    $request = static fn(array $post): Request => new Request(
        'POST', '/likeGJItem211', [], array_merge([
            'gameVersion' => '22',
            'binaryVersion' => '51',
        ], $post),
        ['REMOTE_ADDR' => '127.0.0.4']
    );

    $check($controller->like($request([
        'accountID' => '2',
        'gjp2' => 'pass-2',
        'itemID' => '1',
        'levelID' => '20',
        'type' => '2',
        'like' => '1',
    ]))->body === '1', 'explicit comment type wins over contextual levelID');
    $check($score('comments', 'id', 1) === 1, 'comment counter changed');
    $check($score('levels', 'level_id', 20) === 0, 'level counter unchanged');
    $check($controller->like($request([
        'accountID' => '2',
        'itemID' => '1',
        'type' => '2',
        'like' => '0',
    ]))->body === '-1', 'missing credential cannot fall back to anonymous');

    // Audit pre-existing counters without silently rewriting history.
    $auditor = new VoteIntegrityAuditor($db);
    $clean = $auditor->scan(100, 100, 20);
    $check($clean['targets'][2]['mismatches'] === 0, 'normal votes match stored net score');
    $check($clean['votes']['complete'], 'complete vote scan in the fixture');
    $check($clean['votes']['orphaned'] === 0, 'normal votes have existing targets');

    // Seed historical corruption; the diagnostic must report it, not fix it.
    $db->exec('UPDATE comments SET likes=likes+2 WHERE id=1');
    $db->exec("INSERT INTO likes(item_id,type,account_id,ip,is_like)
               VALUES (999,2,2,'',1), (3,3,1,'',1), (4,4,0,'127.0.0.8',7)");
    $bad = $auditor->scan(100, 100, 20);
    $check($bad['targets'][2]['mismatches'] === 1, 'miscounted comment is detected');
    $check($bad['targets'][3]['mismatches'] === 1, 'self-vote score drift is detected');
    $check($bad['votes']['orphaned'] === 1, 'vote against missing comment is detected');
    $check($bad['votes']['self_votes'] === 1, 'historical self-vote is detected');
    $check($bad['votes']['invalid_values'] === 1, 'invalid vote value is detected');
    $check($score('comments', 'id', 1) === 3, 'auditor never edits historical scores');

    echo "MUCHOCORE_LIKE_INTEGRITY_OK\n";
} finally {
    $db = null;
    $admin->exec('DROP DATABASE IF EXISTS ' . $database);
}
