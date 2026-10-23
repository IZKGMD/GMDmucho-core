<?php
/** GD 2.209 native PHP7 read-only download vs PHP8 encoder byte parity. */
declare(strict_types=1);
require __DIR__ . '/../../compat/php7/runtime.php';
require __DIR__ . '/../../compat/php7/download.php';
require __DIR__ . '/../../src/Protocol/ProtocolText.php';
require __DIR__ . '/../../src/Protocol/GdLegacyText.php';
require __DIR__ . '/../../src/Protocol/GdHash.php';
require __DIR__ . '/../../src/Protocol/GdXor.php';
require __DIR__ . '/../../src/Protocol/GdLevelDownloadEncoder.php';

function checkDownload(bool $test, string $description): void
{
    if (!$test) { throw new RuntimeException($description); }
}
$row = [
    'level_id' => 42, 'account_id' => 4, 'user_id' => 13,
    'name' => 'Test:Level', 'description' => 'Hello world',
    'level_data' => 'kS1,1,2,3;K,4,5,6;',
    'level_version' => 2, 'game_version' => 22,
    'length' => 2, 'difficulty' => 3, 'downloads' => 6,
    'likes' => 7, 'audio_track' => 1, 'demon' => 0,
    'demon_difficulty' => 0, 'auto_level' => 0, 'stars' => 4,
    'featured' => 1, 'epic' => 0, 'object_count' => 14,
    'original_level_id' => 0, 'two_player' => 0, 'coins' => 2,
    'coins_verified' => 1, 'requested_stars' => 5, 'ldm' => 0,
    'song_id' => 0, 'created_at' => '2026-10-20 00:00:00',
    'updated_at' => '2026-10-20 12:00:00',
    'extra_string' => 'test', 'settings_string' => '',
    'copy_password' => '12345', 'song_ids' => '', 'sfx_ids' => '',
    'wt' => 0, 'wt2' => 0, 'ts' => 0,
    'is_deleted' => 0, 'is_unlisted' => 0, 'unlisted2' => 0
];

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);
$columns = [];
foreach ($row as $key => $value) {
    if ($key === 'user_id') { continue; } // resolved by joining profiles
    $columns[] = '"' . $key . '" ' . (is_int($value) ? 'INTEGER' : 'TEXT');
}
$pdo->exec('CREATE TABLE levels(' . implode(',', $columns) . ')');
$pdo->exec('CREATE TABLE profiles (account_id INTEGER,user_id INTEGER)');
$pdo->exec('INSERT INTO profiles VALUES(4,13)');
$values = $row;
unset($values['user_id']);
$names = array_keys($values);
$statement = $pdo->prepare(
    'INSERT INTO levels("' . implode('","', $names) .
    '") VALUES(' . implode(',', array_fill(0, count($names), '?')) . ')'
);
$statement->execute(array_values($values));

$modern = new \MuchoCore\Protocol\GdLevelDownloadEncoder();
foreach ([47, 51] as $binary) {
    $actual = mucho7_dispatch($pdo, '/a/database/downloadGJLevel22.php', 'POST', [
        'levelID' => '42', 'gameVersion' => '22',
        'binaryVersion' => (string)$binary
    ]);
    $expected = $modern->encode($row, 22, false, $binary);
    checkDownload($actual === $expected,
        'downloadGJLevel GD wire parity failed for binaryVersion=' . $binary);
    if ($binary === 51) {
        checkDownload(strpos($actual, ':62:1792454400:63:1792497600') !== false,
            'GD 2.209 download epoch metadata missing');
    }
}
// Native 2.209 can list an existing level whose stored levelString is over
// 1 MiB. Such a level must also download successfully on shared hosting.
$updateData = $pdo->prepare('UPDATE levels SET level_data=? WHERE level_id=42');
$large = 'kS1' . str_repeat('A', 1200000);
$updateData->execute(array($large));
$largeResult = mucho7_dispatch($pdo, '/a/downloadGJLevel22.php', 'POST', array(
    'levelID'=>'42', 'gameVersion'=>'22', 'binaryVersion'=>'51'
));
checkDownload($largeResult === $modern->encode(array_merge($row, array(
    'level_data'=>$large
)), 22, false, 51), 'Valid 2.209 level larger than 1 MiB must download');

// Bound work on legacy shared hosts: unknown 8+ MiB levels must fail closed
// instead of bringing an unexpectedly large LONGTEXT into PHP memory.
$updateData->execute(array('kS1' . str_repeat('B', 8388608)));
checkDownload(mucho7_dispatch($pdo, '/a/downloadGJLevel22.php', 'POST', array(
    'levelID'=>'42', 'gameVersion'=>'22', 'binaryVersion'=>'51'
)) === '-1', 'Oversize 2.209 levelString must not exhaust shared-host memory');
$updateData->execute(array($row['level_data']));
unset($large, $largeResult);

checkDownload(mucho7_dispatch($pdo,'/database/uploadGJLevel21.php',
    'POST',['levelID'=>'42']) === '-1','upload must fail closed');
checkDownload(mucho7_download($pdo,['levelID'=>'-1']) === '-1','timely needs separate auth');
$pdo->exec('CREATE TABLE php7_level_details(level_id INTEGER PRIMARY KEY,level_info TEXT)');
$pdo->exec("INSERT INTO php7_level_details VALUES(42,'test-extra-info')");
checkDownload(mucho7_download($pdo,['levelID'=>'42','extras'=>'1']) ===
    $modern->encode(array_merge($row,['level_info'=>'test-extra-info']),22,true,0),
    'editor extras must match modern wire');
$pdo->exec('UPDATE levels SET is_unlisted=1 WHERE level_id=42');
checkDownload(mucho7_download($pdo,['levelID'=>'42']) === '-1',
    'unlisted level cannot be fetched anonymously');
$pdo->exec('UPDATE levels SET is_unlisted=0,unlisted2=1 WHERE level_id=42');
checkDownload(mucho7_download($pdo,['levelID'=>'42']) === '-1',
    'friend-only level cannot be fetched anonymously');
$pdo->exec('UPDATE levels SET unlisted2=0,is_deleted=1 WHERE level_id=42');
checkDownload(mucho7_download($pdo,['levelID'=>'42']) === '-1',
    'deleted level cannot be fetched');

echo "PHP7_NATIVE_DOWNLOAD_WIRE_PARITY_OK\n";
