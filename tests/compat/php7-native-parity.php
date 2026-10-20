<?php
/** Database-backed public search and PHP8 encoder byte parity. */
declare(strict_types=1);
require __DIR__ . '/../../compat/php7/runtime.php';
require __DIR__ . '/../../src/Protocol/ProtocolText.php';
require __DIR__ . '/../../src/Protocol/GdLegacyText.php';
require __DIR__ . '/../../src/Protocol/GdLevelListEncoder.php';

use MuchoCore\Protocol\GdLevelListEncoder;

function verifyNative7(bool $truth, string $message): void
{
    if (!$truth) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);
$pdo->exec("CREATE TABLE accounts (account_id INTEGER PRIMARY KEY,username TEXT)");
$pdo->exec("CREATE TABLE profiles (account_id INTEGER,user_id INTEGER)");
$pdo->exec("CREATE TABLE songs (id INTEGER PRIMARY KEY,name TEXT,author_id INTEGER,
     author_name TEXT,size REAL,download_url TEXT,youtube_video_id TEXT,
     youtube_channel_id TEXT,is_verified INTEGER)");
$pdo->exec("CREATE TABLE levels (
 level_id INTEGER PRIMARY KEY,account_id INTEGER,name TEXT,description TEXT,
 level_version INTEGER,game_version INTEGER,length INTEGER,difficulty INTEGER,
 downloads INTEGER,likes INTEGER,audio_track INTEGER,demon INTEGER,
 demon_difficulty INTEGER,auto_level INTEGER,stars INTEGER,featured INTEGER,
 epic INTEGER,object_count INTEGER,original_level_id INTEGER,two_player INTEGER,
 coins INTEGER,coins_verified INTEGER,requested_stars INTEGER,ldm INTEGER,
 song_id INTEGER,created_at TEXT,updated_at TEXT,
 is_deleted INTEGER,is_unlisted INTEGER)");

$pdo->exec("INSERT INTO accounts VALUES(4,'DemoPlayer')");
$pdo->exec("INSERT INTO profiles VALUES(4,13)");
$pdo->exec("INSERT INTO songs VALUES(3,'Demo Song',2,'Artist',1.50,
 'https://example.invalid/song.mp3','','',1)");
$pdo->exec("INSERT INTO levels VALUES
 (42,4,'SampleLevel','Hello demo',1,22,2,3,5,12,0,0,0,0,4,1,
 0,22,0,0,2,1,0,0,3,'2026-10-20 00:00:00','2026-10-20 00:00:00',0,0),
 (43,4,'SecretLevel','Private',1,22,2,3,0,1,0,0,0,0,3,0,
 0,10,0,0,0,0,0,0,0,'2026-10-20 00:00:00','2026-10-20 00:00:00',0,1)");
$rows = $pdo->query("
SELECT l.*,a.username,p.user_id,
 s.id AS song_protocol_id,s.name AS song_name,
 s.author_id AS song_author_id,s.author_name AS song_author_name,
 s.size AS song_size,s.youtube_video_id AS song_youtube_video_id,
 s.download_url AS song_download_url,
 s.youtube_channel_id AS song_youtube_channel_id
 FROM levels l
 JOIN accounts a ON a.account_id=l.account_id
 JOIN profiles p ON p.account_id=l.account_id
 LEFT JOIN songs s ON s.id=l.song_id
 WHERE l.level_id=42
")->fetchAll(PDO::FETCH_ASSOC);

$modern = new GdLevelListEncoder();
foreach ([47,51] as $binary) {
    $actual = mucho7_dispatch($pdo,'/a/database/getGJLevels21.php','POST',[
        'type' => '0','str' => '42','gameVersion' => '22',
        'binaryVersion' => (string)$binary,'page' => '0'
    ]);
    $expected = $modern->encode($rows, 1, 0, 10, 22, $binary);
    verifyNative7($actual === $expected,
        'PHP7 native GD search must match current PHP8 wire for binaryVersion ' . $binary);
    if ($binary === 51) {
        verifyNative7(strpos($actual, ':62:1792454400:63:1792454400') !== false,
            '2.209 UTC wire timestamp fields must be preserved');
    }
}
verifyNative7(mucho7_search($pdo,['type'=>0,'str'=>'43','gameVersion'=>22]) === '-2',
    'Unlisted level must not be exposed');
verifyNative7(mucho7_search($pdo,['type'=>0,'str'=>'SampleLevel','page'=>0]) !== '-2',
    'Search by public name');
verifyNative7(mucho7_search($pdo,['type'=>0,'accountID'=>4]) === '-1',
    'User-specific requests must fail closed');
verifyNative7(mucho7_search($pdo,['type'=>5]) === '-1',
    'Private account search type must fail closed');
verifyNative7(mucho7_search($pdo,['type'=>8]) === '-1',
    'Moderator queue must fail closed');
verifyNative7(mucho7_search($pdo,['type'=>0,'len'=>'2']) === '-1',
    'Unsupported filters must fail closed');
verifyNative7(mucho7_search($pdo,['type'=>0,'str'=>str_repeat('a',101)]) === '-1',
    'Oversized level search must fail closed');
verifyNative7(mucho7_search($pdo,['type'=>0,'gameVersion'=>17]) === '-1',
    'Uncertified legacy versions must fail closed');

echo "PHP7_NATIVE_SEARCH_WIRE_PARITY_OK\n";
