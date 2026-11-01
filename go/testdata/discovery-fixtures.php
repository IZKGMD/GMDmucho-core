<?php
// Use ONLY a new disposable muchocore_go_test database. Applies real migrations,
// seeds edge cases, then records responses from the existing PHP controllers.
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'MuchoCore\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require $file; }
    }
});

use MuchoCore\Compatibility\DiscoveryController;
use MuchoCore\Http\Request;
use MuchoCore\Music\SongController;
use MuchoCore\Music\SongRepository;
use MuchoCore\Music\SongService;
use MuchoCore\Protocol\GdSongEncoder;
use MuchoCore\Routing\Router;

$dsn = getenv('MUCHO_GO_PHP_TEST_DSN') ?: '';
if (!preg_match('/;dbname=muchocore_go_test(?:;|$)/', $dsn)) {
    throw new RuntimeException('Set MUCHO_GO_PHP_TEST_DSN to a disposable muchocore_go_test database');
}
$db = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() !== 0) {
    throw new RuntimeException('Refusing to seed a non-empty database');
}
foreach (['001_auth.php', '015_songs.php', '20260929_001_content_packs.php', '20261020_001_gameplay_rotations_rewards.php'] as $file) {
    $migration = require __DIR__ . '/../../database/migrations/' . $file;
    if (is_array($migration)) { foreach ($migration as $sql) { $db->exec($sql); } }
    elseif (is_callable($migration)) { $migration($db); }
    else { $migration->up($db); }
}
// Fixed session clock makes rotation responses deterministic in PHP and Go.
$db->exec('SET timestamp=1700000000');
$db->exec("INSERT INTO songs (id,name,author_id,author_name,size,download_url,youtube_video_id,youtube_channel_id,is_verified) VALUES
    (1,' Test:|#~Song ',7,'Composer',1.235,'https://example.com/1.mp3','video','channel',1),
    (2,'Hidden',7,'Composer',2,'https://example.com/2.mp3',NULL,NULL,0),
    (3,'',0,'',0,'https://example.com/3.mp3',NULL,NULL,1)");
$db->exec("INSERT INTO mucho_gauntlets (id,level1,level2,level3,level4,level5,enabled,sort_order) VALUES
    (1,1,2,3,4,5,1,10), (2,6,7,8,9,10,1,0),
    (3,1,2,3,4,5,0,0), (4,0,2,3,4,5,1,0)");
$insert=$db->prepare('INSERT INTO mucho_map_packs (id,name,levels,stars,coins,difficulty,color1,color2,enabled,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)');
for ($i=1;$i<=12;$i++) {
    $insert->execute([$i, ' Pack:|#~'.$i, '1,2,3', $i, 2, 3, 4, 5, $i===12 ? 0 : 1, 12-$i]);
}
$db->exec("INSERT INTO mucho_daily_rotation (id,kind,level_id,enabled,starts_at,ends_at) VALUES
    (1,'daily',1,1,FROM_UNIXTIME(1699990000),FROM_UNIXTIME(1700003600)),
    (2,'weekly',2,1,FROM_UNIXTIME(1699990000),FROM_UNIXTIME(1700007200)),
    (3,'event',3,1,FROM_UNIXTIME(1699990000),FROM_UNIXTIME(1700010000)),
    (4,'daily',4,0,FROM_UNIXTIME(1699999000),FROM_UNIXTIME(1700010000)),
    (5,'daily',5,1,FROM_UNIXTIME(1699980000),FROM_UNIXTIME(1699999000)),
    (6,'daily',6,1,FROM_UNIXTIME(1700000001),FROM_UNIXTIME(1700010000)),
    (7,'daily',7,1,FROM_UNIXTIME(1699995000),FROM_UNIXTIME(1700005000)),
    (8,'daily',8,1,FROM_UNIXTIME(1699995000),FROM_UNIXTIME(1700006000))");

$controller = new DiscoveryController($db);
$songs = new SongController(new SongService(new SongRepository($db),new GdSongEncoder()));
$router = new Router();
$router->add('ANY','/getGJSongInfo',[$songs,'info']);
foreach (['/getGJGauntlets','/getGJGauntlets21'] as $path) { $router->add('ANY',$path,[$controller,'gauntlets']); }
foreach (['/getGJMapPacks','/getGJMapPacks21'] as $path) { $router->add('ANY',$path,[$controller,'mapPacks']); }
$router->add('ANY','/getGJDailyLevel',[$controller,'daily']);
$cases = [];
foreach (['songID=1','songID=2','songID=3','songID=999','songID=-1','songID=bad','songID=1&songID=3','songID=%2B1','songID=1%0A'] as $form) {
    $cases[]=['/a/database/getGJSongInfo.php',$form];
}
foreach (['getGJGauntlets','getGJGauntlets19','getGJGauntlets20','getGJGauntlets21'] as $name) { $cases[]=['/database/'.$name.'.php','']; }
foreach (['','page=1','page=2','page=-1','page=1001','page=1junk'] as $form) { $cases[]=['/a/getGJMapPacks20.php/',$form]; }
foreach (['','type=0','type=1','type=2','weekly=1','weekly=2','type=0&weekly=1','type=3','type=-1','type=bad'] as $form) {
    $cases[]=['/database/getGJDailyLevel.php',$form];
}
$result=[];
foreach ($cases as [$path,$form]) {
    parse_str($form,$_POST);
    $response=$router->dispatch(new Request('POST',$path,[],$_POST,[]));
    $result[]=['path'=>$path,'form'=>$form,'body'=>$response->body,'status'=>$response->status];
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
