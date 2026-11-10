<?php
declare(strict_types=1);

/** One-off song record for the isolated PHP8 HTTP acceptance tenant. */
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('MUCHO_TEST_ALLOW_WRITES') !== '1' ||
    getenv('DB_NAME') !== 'testgdps' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    getenv('DB_PORT') !== '13307') {
    throw new RuntimeException('Refusing song delivery fixture outside isolated CI');
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$name = (string) getenv('MC_MUSIC_TEST_FILE');
if (!preg_match('/^[a-f0-9]{40}\.mp3$/D', $name)) {
    throw new RuntimeException('Invalid isolated song basename');
}

$db = (new \MuchoCore\Database\Database())->connection();
if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== 'testgdps') {
    throw new RuntimeException('Refusing non-test database');
}

const SONG_ID = 99999002;
const SONG_NAME = 'MuchoCore CI Audio Delivery';

$cmd = $argv[1] ?? '';
if ($cmd === '--create') {
    $check = $db->prepare('SELECT COUNT(*) FROM songs WHERE id=:id');
    $check->execute(['id' => SONG_ID]);
    if ((int)$check->fetchColumn() !== 0) {
        throw new RuntimeException('Reserved song fixture ID already exists');
    }
    $path = dirname(__DIR__, 2) . '/storage/music-public/' . $name;
    if (!is_file($path) || filesize($path) < 256) {
        throw new RuntimeException('MP3 fixture bytes missing');
    }
    $insert = $db->prepare(
        'INSERT INTO songs (id,name,author_id,author_name,size,download_url,is_verified)
         VALUES (:id,:name,0,:artist,:size,:url,1)'
    );
    $insert->execute([
        'id' => SONG_ID, 'name' => SONG_NAME,
        'artist' => 'MuchoCore Original CI Audio',
        'size' => round(filesize($path) / 1048576, 2),
        'url' => 'http://127.0.0.1:18080/music/' . $name,
    ]);
    echo "CREATED\n";
    exit;
}
if ($cmd === '--hide' || $cmd === '--show') {
    $stmt = $db->prepare('UPDATE songs SET is_verified=:state
                         WHERE id=:id AND name=:name');
    $stmt->execute([
        'id' => SONG_ID, 'name' => SONG_NAME,
        'state' => $cmd === '--show' ? 1 : 0,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Song moderation transition failed');
    }
    echo "UPDATED\n";
    exit;
}
if ($cmd === '--delete') {
    $stmt = $db->prepare('DELETE FROM songs WHERE id=:id AND name=:name');
    $stmt->execute(['id' => SONG_ID, 'name' => SONG_NAME]);
    echo "CLEANED\n";
    exit;
}
throw new RuntimeException('Unknown disposable song delivery fixture action');
