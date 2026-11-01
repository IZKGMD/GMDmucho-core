<?php
// Regenerate with PHP 8.2+: php go/testdata/protocol-fixtures.php > go/testdata/protocol.json
declare(strict_types=1);

require __DIR__ . '/../../src/Routing/Router.php';
require __DIR__ . '/../../src/Protocol/GdHash.php';
require __DIR__ . '/../../src/Protocol/GdXor.php';
require __DIR__ . '/../../src/Protocol/ProtocolText.php';
require __DIR__ . '/../../src/Protocol/GdSongEncoder.php';

use MuchoCore\Protocol\GdHash;
use MuchoCore\Protocol\GdXor;
use MuchoCore\Protocol\GdSongEncoder;
use MuchoCore\Protocol\ProtocolText;
use MuchoCore\Routing\Router;

$router = new Router();
$paths = ['/', '', '/a/database/getGJLevels20.php', '/api/getGJLevels20.php/',
    '/accounts/loginGJAccount22.php', '/A/loginGJAccount.php?ignored=1',
    '/a//API/accounts/DATABASE/getGJMapPacks19.PHP/', '/checkIfServerOnline.php',
    '/database', '/api/', '/.php', '/x/../health', '/api/getGJSongInfo.php',
    '/database/getGJDailyLevel.php', '/database/getGJGauntlets20.php'];
// Exercise every alias directly from the authoritative PHP source.
preg_match_all("~'(/[^']+)' => '(/[^']+)'~", file_get_contents(__DIR__ . '/../../src/Routing/Router.php'), $aliases);
$paths = array_merge($paths, $aliases[1]);
$result = ['paths' => [], 'hashes' => [], 'usernames' => [], 'passwords' => [], 'songs' => []];
foreach ($paths as $path) {
    $result['paths'][] = ['input' => $path, 'output' => $router->normalizePath($path)];
}
foreach (['', 'abc', str_repeat('x', 40), str_repeat('y', 41), str_repeat('0123456789', 13), "\x00\xffabc", str_repeat('Level data ', 1000)] as $data) {
    $result['hashes'][] = ['input' => base64_encode($data), 'level' => GdHash::level($data),
        'metadata' => GdHash::metadata($data), 'rewards' => GdHash::rewards($data)];
}
foreach (['', " \tPlayer\r\n", 'abc:|#~def', "\x01\x7fabc", '1234567890123456789ЖЖ', "\fhello\f"] as $value) {
    $result['usernames'][] = ['input' => base64_encode($value), 'output' => base64_encode(ProtocolText::username($value))];
}
foreach (['', '0', '1', '123456', '999999', "\x00\xffabc"] as $password) {
    $result['passwords'][] = ['input' => base64_encode($password), 'output' => GdXor::copyPassword($password)];
}
foreach ([0, -0.0, -0.001, 1, 1.005, 1.234, 1.235, 2.675, 16.225, 0.145, -1.235, 999.999] as $size) {
    $song = ['id' => 42, 'name' => ' Test:|#~Song ', 'author_id' => 7, 'author_name' => '',
        'size' => $size, 'download_url' => 'https://example.com/song.mp3',
        'youtube_video_id' => 'abc', 'youtube_channel_id' => 'channel'];
    $result['songs'][] = ['size' => $size, 'output' => (new GdSongEncoder())->encode($song)];
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
