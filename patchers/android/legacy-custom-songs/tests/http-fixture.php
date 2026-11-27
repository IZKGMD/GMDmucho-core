<?php

declare(strict_types=1);

use MuchoCore\Http\Request;
use MuchoCore\Plugin\PluginManager;
use MuchoCore\Routing\Router;

if (PHP_SAPI !== 'cli-server') {
    exit(1);
}

$root = getenv('MUCHO_TEST_CORE_PATH');
$music = getenv('MUCHO_LEGACY_SONGS_DIR');
if (!is_string($root) || !is_string($music) || !is_file($music . '/fixture.mp3')) {
    throw new RuntimeException('Configure a local MuchoCore checkout and the test MP3');
}
require $root . '/vendor/autoload.php';

$request = Request::fromGlobals();
error_log('[legacy fixture] ' . $request->method . ' ' . $request->path);
if ($request->path === '/music/fixture.mp3') {
    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . filesize($music . '/fixture.mp3'));
    readfile($music . '/fixture.mp3');
    return;
}

$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE songs (id INTEGER, name TEXT, author_name TEXT, download_url TEXT, is_verified INT)');
$insert = $db->prepare('INSERT INTO songs VALUES (?, ?, ?, ?, ?)');
for ($i = 1; $i <= 18; $i++) {
    $insert->execute([$i, "QA tone $i", 'MuchoCore', 'http://localhost/music/fixture.mp3', 1]);
}
$insert->execute([19, 'Hidden', 'MuchoCore', '/music/fixture.mp3', 0]);
$insert->execute([20, 'External', 'MuchoCore', 'https://example.invalid/other/fixture.mp3', 1]);
$insert->execute([21, 'Missing', 'MuchoCore', '/music/missing.mp3', 1]);
$insert->execute([22, 'Query', 'MuchoCore', '/music/fixture.mp3?v=1', 1]);

$db->exec('CREATE TABLE levels (level_id INTEGER, song_id INTEGER, is_deleted INT, is_unlisted INT, unlisted2 INT)');
$db->exec('INSERT INTO levels VALUES (42, 1, 0, 0, 0), (43, 0, 0, 0, 0), (44, 1, 0, 1, 0), (45, 19, 0, 0, 0)');

$router = new Router();
$plugins = new PluginManager($db, $router, dirname(__DIR__) . '/server', '1.1.1');
$plugins->load();
if (!isset($plugins->loaded()['legacy-songs'])) {
    throw new RuntimeException('Legacy Songs plugin is unavailable');
}
$router->dispatch($request)->send();
