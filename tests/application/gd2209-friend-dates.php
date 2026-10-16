<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Social/RelationshipRepository.php';
require dirname(__DIR__, 2) . '/src/Protocol/GdRelationshipEncoder.php';
require dirname(__DIR__, 2) . '/src/Protocol/ProtocolText.php';

use MuchoCore\Social\RelationshipRepository;
use MuchoCore\Protocol\GdRelationshipEncoder;

function expect2209(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('TEST_DB_PORT') ?: '3306';
$pass = getenv('TEST_DB_ROOT_PASSWORD') ?: '';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$root = new PDO("mysql:host=$host;port=$port", 'root', $pass, $options);
$name = 'mc_gd2209_' . bin2hex(random_bytes(5));
$root->exec("CREATE DATABASE $name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", 'root', $pass, $options);
    $migration = require dirname(__DIR__, 2) . '/database/migrations/20261016_001_gd2209_friend_dates.php';
    $migration($db);
    $db->exec("INSERT INTO friends(account_id,friend_account_id,is_new,created_at) VALUES(1,2,0,'2025-01-01 00:00:00')");
    $before = $db->query('SELECT UNIX_TIMESTAMP(created_at) FROM friends')->fetchColumn();
    $migration($db);
    expect2209($db->query('SELECT UNIX_TIMESTAMP(created_at) FROM friends')->fetchColumn() === $before, 'Migration changed a known date.');

    // Existing installations can lack the timestamp. Historical dates
    // must not be replaced with the current upgrade date.
    $db->exec('ALTER TABLE friends DROP COLUMN created_at');
    $migration($db);
    expect2209($db->query('SELECT created_at FROM friends')->fetchColumn() === null, 'Unknown date was invented.');
    $db->exec('DELETE FROM friends');
    $db->exec('CREATE TABLE accounts(account_id BIGINT UNSIGNED PRIMARY KEY,username VARCHAR(32))');
    $db->exec('CREATE TABLE profiles(account_id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED,cube INT,color1 INT,color2 INT,special INT)');
    $db->exec('CREATE TABLE blocks(account_id BIGINT UNSIGNED,blocked_account_id BIGINT UNSIGNED)');
    $db->exec('CREATE TABLE friend_requests(id BIGINT UNSIGNED PRIMARY KEY,account_id BIGINT UNSIGNED,to_account_id BIGINT UNSIGNED)');
    $db->exec('CREATE TABLE mucho_clans(clan_id BIGINT UNSIGNED,tag VARCHAR(12))');
    $db->exec('CREATE TABLE mucho_clan_members(clan_id BIGINT UNSIGNED,account_id BIGINT UNSIGNED)');
    $db->exec("INSERT INTO accounts VALUES(1,'First'),(2,'Second')");
    $db->exec('INSERT INTO profiles VALUES(1,10,1,0,3,0),(2,20,1,0,3,0)');
    $db->exec('INSERT INTO friend_requests VALUES(1,1,2)');
    $repo = new RelationshipRepository($db);
    expect2209($repo->accept(2, 1), 'Friend request acceptance failed.');
    $dates = $db->query('SELECT UNIX_TIMESTAMP(created_at) FROM friends ORDER BY account_id')->fetchAll(PDO::FETCH_COLUMN);
    expect2209(count($dates) === 2 && $dates[0] === $dates[1] && (int)$dates[0] > 0, 'Both sides need the same real timestamp.');
    $encoder = new GdRelationshipEncoder();
    foreach ([1, 2] as $id) {
        $wire = $encoder->users($repo->userList($id, 0));
        expect2209(str_contains($wire, ':64:' . $dates[0]), 'Friends-since field missing from friend-list data.');
    }
    $db->exec('INSERT INTO blocks VALUES(1,2)');
    expect2209(!str_contains($encoder->users($repo->userList(1, 1)), ':64:'), 'Blocked-list rows must not inherit friend dates.');
    echo "GD2209_FRIEND_DATES_DB_OK\n";
} finally {
    $db = null;
    $root->exec("DROP DATABASE $name");
}
