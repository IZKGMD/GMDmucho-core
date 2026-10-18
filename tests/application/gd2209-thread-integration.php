<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Social\ThreadService;
use MuchoCore\Social\ThreadController;
use MuchoCore\Protocol\GdRelationshipEncoder;
use MuchoCore\Http\Request;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('Refusing GD 2.209 thread tests outside isolated CI MariaDB.');
}
$connect = static fn(?string $db): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port . ($db ? ';dbname=' . $db : '') . ';charset=utf8mb4',
    'root', $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$check = static function (bool $ok, string $why): void {
    if (!$ok) {
        throw new RuntimeException('2.209 thread test: ' . $why);
    }
};
$name = 'mc_threads_it_' . bin2hex(random_bytes(5));
$server = $connect(null);
try {
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4');
    $db = $connect($name);
    $db->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        username VARCHAR(20) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE profiles (
        user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL UNIQUE,
        cube INT NOT NULL DEFAULT 1,
        color1 INT NOT NULL DEFAULT 0,
        color2 INT NOT NULL DEFAULT 3,
        special INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE blocks (
        account_id BIGINT UNSIGNED NOT NULL,
        blocked_account_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY(account_id, blocked_account_id)
    ) ENGINE=InnoDB');
    $migration = require dirname(__DIR__, 2) . '/database/migrations/20261016_005_gd2209_threads.php';
    foreach ($migration as $sql) {
        $db->exec($sql);
    }
    foreach ([1=>'Owner',2=>'Member',3=>'Stranger'] as $id=>$username) {
        $stmt = $db->prepare(
            'INSERT INTO accounts(account_id,username,password_hash,gjp2_hash)
             VALUES (:id,:username,:password,:gjp)'
        );
        $stmt->execute([
            'id'=>$id, 'username'=>$username,
            'password'=>password_hash('pass-'.$id, PASSWORD_DEFAULT),
            'gjp'=>password_hash('gjp-'.$id, PASSWORD_DEFAULT),
        ]);
        $db->prepare('INSERT INTO profiles(account_id) VALUES (:id)')
            ->execute(['id'=>$id]);
    }

    $service = new ThreadService($db, new AccountAuthenticator($db));
    $check($service->create(1,'pass-1','') === 0, 'empty title must be rejected');
    $thread = $service->create(1,'pass-1','GDPS Team');
    $check($thread > 0, 'thread should be created');
    $check(count($service->threads(1,'pass-1',0)) === 1, 'owner sees thread');
    $check(count($service->threads(2,'pass-2',0)) === 0, 'nonmembers cannot list thread');
    $check(count($service->members(1,'pass-1',$thread)) === 1, 'creator is first member');
    $check(count($service->members(2,'pass-2',$thread)) === 0, 'member list private');

    $http = new ThreadController($service, new GdRelationshipEncoder());
    $req = static fn(int $account,string $password,string $path,array $extra=[]): Request =>
        new Request('POST','/database/'.$path.'.php',[],array_merge([
            'accountID'=>(string)$account, 'gjp2'=>$password,
            'gameVersion'=>'22','binaryVersion'=>'51',
            'threadID'=>(string)$thread,
        ],$extra),[]);
    $check($http->getMembers($req(2,'pass-2','getGJThreadMembers'))->body === '-1',
        'outsider cannot enumerate the native roster');
    $check($http->getMessages($req(2,'pass-2','getGJThreadMessages'))->body === '-1',
        'outsider cannot receive a falsely successful seven-part thread');

    $check(!$service->send(2,'pass-2',$thread,'Hack'), 'nonmember cannot send');
    $check($service->messages(2,'pass-2',$thread,0) === [], 'nonmember cannot read');
    $check(!$service->modify(2,'pass-2',$thread,'rename','Hijacked'), 'non-owner cannot rename');
    $check(!$service->invite(2,'pass-2',$thread,3), 'non-owner cannot invite');
    $check($service->invite(1,'pass-1',$thread,2), 'owner invites member');
    $check($service->invite(1,'pass-1',$thread,2), 'repeat invite is idempotent');
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_thread_invites')->fetchColumn() === 1,
        'duplicate invitation is not inserted'
    );
    $invitations = $service->invites(2,'pass-2');
    $check(count($invitations) === 1, 'recipient receives invite');
    $check($service->invites(3,'pass-3') === [], 'other users cannot read invitation');
    $invite = (int)$invitations[0]['id'];
    $check(
        !$service->respondInvite(3,'pass-3',$invite,true),
        'third-party invite acceptance denied'
    );
    $check($service->respondInvite(2,'pass-2',$invite,true), 'recipient can accept');
    $check(!$service->respondInvite(2,'pass-2',$invite,true), 'cannot double-accept');
    $check(count($service->members(2,'pass-2',$thread)) === 2, 'joined member visible');
    $check($service->send(2,'pass-2',$thread,'Hello there!'), 'joined member sends');
    $check(count($service->messages(1,'pass-1',$thread,0)) === 1, 'owner reads messages');
    $check(count($service->messages(2,'pass-2',$thread,0)) === 1, 'member reads messages');
    $check(count($service->search(2,'pass-2',$thread,'Hello',0)) === 1,
        'member searches own thread');
    $check($service->search(3,'pass-3',$thread,'Hello',0) === [],
        'outsider cannot search messages');
    $check($service->search(2,'pass-2',$thread,'there%xx',0) === [],
        'search wildcard must not disclose unrelated messages');

    $snapshot = $service->nativeSnapshot(1,'pass-1',$thread);
    $check(count($snapshot['messages']) === 1 && count($snapshot['members']) === 2,
        'only active members receive full native snapshot');
    $wire = $http->getMessages($req(1,'pass-1','getGJThreadMessages'))->body;
    $parts = explode('#',$wire);
    $check(count($parts) === 7 && str_contains($parts[0], base64_encode('Hello there!')),
        'real native thread reply has seven sections with positional messages');
    $check($parts[5] === '0' && $parts[6] === '3,1,10,50',
        'native thread trailing segments');

    $list = $http->getThreads($req(1,'pass-1','getGJThreads'))->body;
    $listParts = explode('#',$list);
    $check(count($listParts) === 2 && $listParts[1] === '1:0:10:0:60'
        && count(explode(':',$listParts[0])) === 26,
        'native thread list uses fixed positional record and page envelope');

    $sent = $http->send($req(2,'pass-2','sendGJThreadMessage',[
        'body'=>rtrim(strtr(base64_encode('Client ping'),'+/','-_'),'=')
    ]))->body;
    $check(preg_match('/^'.preg_quote((string)$thread,'/').':[1-9][0-9]*$/D',$sent) === 1,
        'native send responds with persisted threadID:messageID');
    $sentId = (int)explode(':',$sent)[1];
    $check((int)$db->query('SELECT COUNT(*) FROM mucho_thread_messages
        WHERE id='.$sentId.' AND body="Client ping"')->fetchColumn() === 1,
        'native Base64 body must be decoded before storing actual message');
    $check($http->send($req(2,'pass-2','sendGJThreadMessage',[
        'body'=>'**not-valid**'
    ]))->body === '-1', 'malformed Base64 content rejected');

    $check($service->modify(1,'pass-1',$thread,'rename','Renamed'), 'owner renames');
    $check(
        $service->threads(1,'pass-1',0)[0]['title'] === 'Renamed',
        'rename persisted'
    );
    $db->exec('INSERT INTO blocks(account_id,blocked_account_id) VALUES (3,1)');
    $check(!$service->invite(1,'pass-1',$thread,3), 'blocked user cannot be invited');
    $db->exec('DELETE FROM blocks');
    $check($service->invite(1,'pass-1',$thread,3), 'owner can invite third user');
    $otherInvite = (int)$service->invites(3,'pass-3')[0]['id'];
    $check($service->respondInvite(3,'pass-3',$otherInvite,false), 'recipient declines');
    $check($service->members(3,'pass-3',$thread) === [], 'declined recipient remains out');
    $check($service->leave(2,'pass-2',$thread), 'member can leave');
    $check(!$service->leave(1,'pass-1',$thread), 'owner cannot orphan thread by leaving');
    $check(!$service->send(2,'pass-2',$thread,'After leaving'), 'left member cannot send');
    $check($service->modify(1,'pass-1',$thread,'close'), 'owner closes thread');
    $check(!$service->send(1,'pass-1',$thread,'Closed'), 'closed thread rejects messages');
    $check(!$service->invite(1,'pass-1',$thread,3), 'closed thread rejects invites');

    foreach ([
        static fn() => $service->threads(1,'pass-2',0),
        static fn() => $service->create(1,'pass-2','No'),
        static fn() => $service->invite(1,'pass-2',$thread,3),
        static fn() => $service->messages(1,'pass-2',$thread,0),
        static fn() => $service->send(1,'pass-2',$thread,'No'),
    ] as $attack) {
        try {
            $attack();
            throw new RuntimeException('Invalid credential was not rejected');
        } catch (RuntimeException $e) {
            $check($e->getMessage() === 'Unauthorized.', 'account credentials enforced');
        }
    }

    $db->exec('DELETE FROM accounts WHERE account_id=1');
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_threads')->fetchColumn() === 0,
        'deleting owner cascades thread deletion'
    );
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_thread_messages')->fetchColumn() === 0,
        'deleting thread cascades messages'
    );
    echo "MUCHOCORE_GD_2209_THREAD_INTEGRATION_OK\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $name);
}
