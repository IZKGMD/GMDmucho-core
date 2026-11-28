<?php

declare(strict_types=1);

$files = [
    __DIR__ . '/../../public/admin/index.php',
    __DIR__ . '/../../public/api/v2/music-upload.php',
    __DIR__ . '/../../docker/Dockerfile',
    __DIR__ . '/../../public/admin/pages/songs.php',
];

foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FAIL missing {$file}\n");
        exit(1);
    }
}

$admin = file_get_contents($files[3]);
$api = file_get_contents($files[1]);
$docker = file_get_contents($files[2]);

$checks = [
    [file_get_contents($files[0]), "require __DIR__.'/pages/songs.php'", 'admin music module is routed'],
    [$admin, 'name="music_file"', 'admin music upload field'],
    [$admin, "enctype=\"multipart/form-data\"", 'admin multipart form'],
    [$admin, "action\" value=\"music-upload", 'admin music action'],
    [$api, "mkdir(MUSIC_DIR", 'api creates music storage'],
    [$api, "const MUSIC_DIR = MUCHO_V2_ROOT . '/storage/music-public';", 'api music storage resolves from installed root'],
    [!str_contains($api, "'/var/www/mucho-core/storage/music-public'") ? 'portable' : '', 'portable', 'api never hardcodes Docker-only upload directory'],
    [$api, "MUSIC_MAX = 20 * 1024 * 1024", 'api music size limit'],
    [$docker, 'upload_max_filesize=64M', 'php upload_max_filesize'],
    [$docker, 'post_max_size=70M', 'php post_max_size'],
];

foreach ($checks as [$haystack, $needle, $name]) {
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

echo "MUCHOCORE_MUSIC_CONTRACT_OK\n";

require_once __DIR__ . '/../../src/Music/NewgroundsSource.php';
require_once __DIR__ . '/../../src/Music/MusicLibrary.php';
require_once __DIR__ . '/../../patchers/android/legacy-custom-songs/server/legacy-songs/library.php';

use MuchoCore\Music\MusicLibrary;
use MuchoCore\Music\NewgroundsSource;
use MuchoCoreLegacySongs\NetworkCatalog;

function musicCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

musicCheck(NewgroundsSource::parse('https://newgrounds.com/audio/listen/12345') === 12345, 'Newgrounds URL');
musicCheck(NewgroundsSource::parse('12345') === 12345, 'Newgrounds ID');
musicCheck(NewgroundsSource::parse('') === null, 'Optional source');
foreach (['https://newgrounds.com.example/audio/listen/12345', 'https://example.com/audio/listen/1',
          'https://newgrounds.com/audio/listen/1?download=1', '2147483648', ['1']] as $input) {
    try {
        NewgroundsSource::parse($input);
        throw new RuntimeException('Invalid Newgrounds source accepted');
    } catch (InvalidArgumentException) {
    }
}

$musicDir = sys_get_temp_dir() . '/mucho-music-contract-' . bin2hex(random_bytes(8));
mkdir($musicDir, 0700);
$mp3 = $musicDir . '/fixture.mp3';
file_put_contents($mp3, 'music library file fixture');
try {
    $db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE songs (id INTEGER, name TEXT, author_name TEXT, download_url TEXT, is_verified INT, newgrounds_id INT)');
    $db->exec("INSERT INTO songs VALUES
        (1, 'Pending', 'Author', '/music/fixture.mp3', 0, 12345),
        (2, 'Missing MP3', 'Author', '/music/missing.mp3', 1, 12345),
        (3, 'Track', 'Author', '/music/fixture.mp3', 1, 12345),
        (4, 'Local', 'Artist', '/music/fixture.mp3', 1, NULL),
        (5, 'Pending only', 'Artist', '/music/fixture.mp3', 0, 12346),
        (12345, 'Same number, different song', 'Artist', '/music/fixture.mp3', 1, NULL)");
    $db->exec('CREATE TABLE levels (level_id INTEGER, song_id INTEGER, is_deleted INT, is_unlisted INT, unlisted2 INT)');
    $db->exec('INSERT INTO levels VALUES (40, 3, 0, 0, 0), (41, 0, 0, 0, 0), (42, 3, 0, 1, 0), (43, 12345, 0, 0, 0)');

    $catalog = new NetworkCatalog($db, ['/music/' => $musicDir]);
    $library = new MusicLibrary($db, $catalog);
    [$status, $body] = $library->handle('catalog', []);
    musicCheck($status === 200 && array_column($body['tracks'], 'id') === [3, 4, 12345], 'Only approved playable MP3s');
    $source = ['provider' => 'newgrounds', 'id' => 12345, 'url' => 'https://www.newgrounds.com/audio/listen/12345'];
    musicCheck($body['tracks'][0]['source'] === $source && $body['tracks'][1]['source'] === null, 'Catalog attribution');
    [$status, $body] = $library->handle('newgrounds', ['id' => '12345']);
    musicCheck($status === 200 && $body['track']['id'] === 3 && $body['track']['source'] === $source, 'Audio ID resolves to MuchoCore ID');
    musicCheck($body['track']['sha256'] === hash_file('sha256', $mp3), 'MP3 checksum');
    musicCheck($library->handle('track', ['id' => '3']) === [$status, $body], 'Stored track ID resolves to the same track');
    [$localStatus, $local] = $library->handle('muchocore', ['id' => '12345']);
    musicCheck($localStatus === 200 && $local['track']['id'] === 12345
        && $local['track']['source'] === null && $local['track']['title'] !== $body['track']['title'], 'Local and Newgrounds IDs are independent');
    musicCheck($library->handle('muchocore', ['id' => '3'])[0] === 404, 'Newgrounds recording is not a local ID alias');
    musicCheck($library->handle('muchocore', ['id' => ['1']])[0] === 400, 'Invalid local ID');
    [$levelStatus, $level] = $library->handle('level', ['id' => '40']);
    musicCheck($levelStatus === 200 && $level['track'] === $body['track'], 'Level resolves to the same track');
    musicCheck($library->handle('level', ['id' => '41']) === [200, ['version' => 1, 'level_id' => 41, 'track' => null]], 'Built-in song');
    musicCheck($library->handle('level', ['id' => '42'])[0] === 404, 'Unlisted level');
    musicCheck($library->handle('level', ['id' => '43'])[1]['track'] === $local['track'], 'Local track survives the level association');
    musicCheck($library->handle('newgrounds', ['id' => '12346'])[0] === 404, 'Pending source');
    musicCheck($library->handle('newgrounds', ['id' => ['12345']])[0] === 400, 'Malformed source query');
    musicCheck(!array_key_exists('source', $catalog->handle('track', ['id' => '3'])[1]['track']), 'Legacy contract');
} finally {
    unlink($mp3);
    rmdir($musicDir);
}

echo "MUCHOCORE_MUSIC_LIBRARY_OK\n";
