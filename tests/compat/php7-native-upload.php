<?php
/** SQLite unit test for owner-only GD upload on experimental PHP7 runtime. */
declare(strict_types=1);
require dirname(__DIR__,2) . '/compat/php7/runtime.php';
require dirname(__DIR__,2) . '/compat/php7/auth.php';
require dirname(__DIR__,2) . '/compat/php7/upload.php';
function checkUpload($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE accounts(
 account_id INTEGER PRIMARY KEY,username TEXT,password_hash TEXT,gjp2_hash TEXT,
 is_active INTEGER,is_banned INTEGER)");
$pdo->exec("CREATE TABLE levels(
 level_id INTEGER PRIMARY KEY AUTOINCREMENT,account_id INTEGER,name TEXT,
 description TEXT,level_data TEXT,level_version INTEGER,game_version INTEGER,
 length INTEGER,audio_track INTEGER,song_id INTEGER,object_count INTEGER,
 coins INTEGER,requested_stars INTEGER,is_unlisted INTEGER,unlisted2 INTEGER,
 copy_password TEXT,ldm INTEGER,is_deleted INTEGER DEFAULT 0,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("ALTER TABLE levels ADD COLUMN extra_string TEXT");
$pdo->exec("ALTER TABLE levels ADD COLUMN settings_string TEXT");
$pdo->exec("ALTER TABLE levels ADD COLUMN song_ids TEXT");
$pdo->exec("ALTER TABLE levels ADD COLUMN sfx_ids TEXT");
$pdo->exec("ALTER TABLE levels ADD COLUMN original_level_id INTEGER DEFAULT 0");
$pdo->exec("ALTER TABLE levels ADD COLUMN two_player INTEGER DEFAULT 0");
$pdo->exec("ALTER TABLE levels ADD COLUMN wt INTEGER DEFAULT 0");
$pdo->exec("ALTER TABLE levels ADD COLUMN wt2 INTEGER DEFAULT 0");
$pdo->exec("ALTER TABLE levels ADD COLUMN ts INTEGER DEFAULT 0");
$pdo->exec("CREATE TABLE php7_level_details(level_id INTEGER PRIMARY KEY,level_info TEXT)");
$pw = 'TestPass1234';
$hash = sha1($pw.'mI29fmAnxgTs');
$stored = password_hash($pw, PASSWORD_BCRYPT, ['cost'=>4]);
$storedGjp = password_hash($hash, PASSWORD_BCRYPT, ['cost'=>4]);
$stmt=$pdo->prepare('INSERT INTO accounts VALUES (?,?,?,?,1,0)');
$stmt->execute([1,'Owner',$stored,$storedGjp]);
$stmt->execute([2,'Another',$stored,$storedGjp]);
$base=[
    'accountID'=>'1', 'gjp2'=>$hash, 'gameVersion'=>'22', 'binaryVersion'=>'51',
    'levelName'=>'My Test Level', 'levelString'=>'kS1,4,5,6;',
    'levelDesc'=>strtr(base64_encode('My test description'),'+/','-_'),
    'levelVersion'=>'1','requestedStars'=>'5'
];
$opts=['enable_accounts'=>true, 'enable_writes'=>true];
checkUpload(mucho7_dispatch($pdo,'/database/uploadGJLevel21.php',
 'POST',$base) === '-1', 'writes default off');
checkUpload(mucho7_dispatch($pdo,'/database/uploadGJLevel21.php',
 'POST',$base,$opts)==='1','owner upload should generate ID');
checkUpload($pdo->query('SELECT name FROM levels WHERE level_id=1')->fetchColumn()==='My Test Level',
 'uploaded title stored in DB');
checkUpload($pdo->query('SELECT description FROM levels WHERE level_id=1')->fetchColumn()==='My test description',
 'uploaded GD description decoded');
$other=$base; $other['accountID']='2';$other['levelID']='1';
$other['levelName']='Stolen';
checkUpload(mucho7_dispatch($pdo,'/database/uploadGJLevel21.php',
 'POST',$other,$opts)==='-1','other account cannot overwrite level');
checkUpload($pdo->query('SELECT name FROM levels WHERE level_id=1')->fetchColumn()==='My Test Level',
 'original owner data remains unchanged');
$update=$base;$update['levelID']='1';$update['levelName']='Owner Updated';
checkUpload(mucho7_dispatch($pdo,'/database/uploadGJLevel21.php',
 'POST',$update,$opts)==='1','owner may update own level');
checkUpload($pdo->query('SELECT name FROM levels WHERE level_id=1')->fetchColumn()==='Owner Updated',
 'owned update persisted');
$bad=$base;$bad['levelID']='1';$bad['gjp2']='invalid';
checkUpload(mucho7_dispatch($pdo,'/database/uploadGJLevel21.php',
 'POST',$bad,$opts)==='-1','invalid credential rejected');
$bad=$base;$bad['levelString']=str_repeat('x',8*1024*1024+1);
checkUpload(mucho7_dispatch($pdo,'/database/uploadGJLevel21.php',
 'POST',$bad,$opts)==='-1','oversize upload rejected');
$pdo->exec('UPDATE accounts SET is_banned=1 WHERE account_id=1');
checkUpload(mucho7_dispatch($pdo,'/database/uploadGJLevel21.php',
 'POST',$base,$opts)==='-1','banned owner cannot upload');
echo "PHP7_NATIVE_OWNER_UPLOAD_OK\n";
