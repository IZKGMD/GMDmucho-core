<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Protocol\GdRelationshipEncoder;
use MuchoCore\Social\RelationshipRepository;

function socialLegacyCheck(bool $ok, string $reason): void
{
    if (!$ok) {
        throw new RuntimeException('GD 2.209 missing-profile social: ' . $reason);
    }
}

$db = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$db->exec('CREATE TABLE accounts(
    account_id INT PRIMARY KEY,username TEXT NOT NULL,
    is_active INT DEFAULT 1,is_banned INT DEFAULT 0
)');
$db->exec('CREATE TABLE profiles(
    account_id INT UNIQUE,user_id INT,cube INT,color1 INT,color2 INT,special INT,
    stars INT,demons INT,diamonds INT,moons INT,creator_points INT,
    secret_coins INT,user_coins INT
)');
$db->exec('CREATE TABLE friend_requests(
    id INTEGER PRIMARY KEY,account_id INT,to_account_id INT,
    comment TEXT,is_read INT,created_at TEXT
)');
$db->exec('CREATE TABLE friends(
    account_id INT,friend_account_id INT,is_new INT
)');
$db->exec('CREATE TABLE blocks(account_id INT,blocked_account_id INT)');
$db->exec('CREATE TABLE mucho_follows(follower_account_id INT,followed_account_id INT)');
$db->exec('CREATE TABLE mucho_muted_users(account_id INT,muted_account_id INT)');
$db->exec('CREATE TABLE mucho_clans(clan_id INT,tag TEXT)');
$db->exec('CREATE TABLE mucho_clan_members(clan_id INT,account_id INT)');
$db->exec("INSERT INTO accounts VALUES
    (1,'Viewer',1,0),(2,'LegacyFriend',1,0),
    (3,'LegacySender',1,0),(4,'LegacyFollow',1,0)");
$db->exec("INSERT INTO profiles VALUES (1,1001,6,9,12,0,4,2,200,4,1,0,1)");
$db->exec("INSERT INTO friend_requests VALUES
    (10,1,2,'outgoing',0,'2026-11-18 00:00:00'),
    (11,3,1,'incoming',0,'2026-11-18 00:00:00')");
$db->exec('INSERT INTO friends VALUES(1,2,1)');
$db->exec('INSERT INTO blocks VALUES(1,3)');
$db->exec('INSERT INTO mucho_follows VALUES(1,4)');
$db->exec('INSERT INTO mucho_muted_users VALUES(1,4)');

$repo = new RelationshipRepository($db);
$encoder = new GdRelationshipEncoder();
socialLegacyCheck($repo->requestCount(1, true) === 1, 'sent request count');
socialLegacyCheck($repo->requestCount(1, false) === 1, 'inbox request count');
$outgoing = $repo->requests(1, true, 0);
$incoming = $repo->requests(1, false, 0);
socialLegacyCheck(count($outgoing) === 1 && $outgoing[0]['username'] === 'LegacyFriend',
    'sent request remains visible without recipient profile');
socialLegacyCheck(count($incoming) === 1 && $incoming[0]['username'] === 'LegacySender',
    'inbox request remains visible without sender profile');
socialLegacyCheck(str_contains($encoder->requests($outgoing, 1, 0, true), '1:LegacyFriend:2:2:9:1:10:0:11:3'),
    'outgoing request uses safe default icon and account user ID');
socialLegacyCheck(str_contains($encoder->requests($incoming, 1, 0, false), '1:LegacySender:2:3:9:1:10:0:11:3'),
    'inbound request uses safe default icon and account user ID');

foreach ([0 => 'LegacyFriend', 1 => 'LegacySender',
          3 => 'LegacyFollow', 4 => 'LegacyFollow'] as $type => $name) {
    $users = $repo->userList(1, $type);
    socialLegacyCheck(count($users) === 1 && $users[0]['username'] === $name,
        'user list type '.$type.' includes missing-profile peer');
    socialLegacyCheck(str_contains($encoder->users($users, true), '1:'.$name.':2:'),
        'user list type '.$type.' encodes missing-profile peer safely');
}
socialLegacyCheck((int)$db->query(
    'SELECT is_new FROM friends WHERE account_id=1 AND friend_account_id=2'
)->fetchColumn() === 0, 'opening friends list still clears new marker');

echo "MUCHOCORE_GD2209_LEGACY_SOCIAL_PROFILES_OK\n";
