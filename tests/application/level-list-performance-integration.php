<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Level\LevelRepository;
use MuchoCore\Protocol\GdLevelListEncoder;

// A real isolated MariaDB test verifies level search, wire metadata and
// absence of large level_data blobs in list results (download unchanged).
if (getenv('TEST_DB_HOST') !== '127.0.0.1'
    || !(getenv('TEST_DB_ROOT_PASSWORD') ?: false)) {
    throw new RuntimeException('Only run this test against isolated local CI MariaDB.');
}
$root = new PDO(
    'mysql:host=127.0.0.1;port=' . (getenv('TEST_DB_PORT') ?: '3306') . ';charset=utf8mb4',
    'root',
    (string)getenv('TEST_DB_ROOT_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$name = 'mc_listperf_' . bin2hex(random_bytes(5));
$root->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4');
try {
    $db = new PDO(
        'mysql:host=127.0.0.1;port=' . (getenv('TEST_DB_PORT') ?: '3306')
            . ';dbname=' . $name . ';charset=utf8mb4',
        'root', (string)getenv('TEST_DB_ROOT_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $db->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED PRIMARY KEY,
        username VARCHAR(30) NOT NULL
    )');
    $db->exec('CREATE TABLE profiles (
        account_id BIGINT UNSIGNED PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL
    )');
    $db->exec('CREATE TABLE songs (
        id BIGINT UNSIGNED PRIMARY KEY,
        name VARCHAR(64) NOT NULL,
        author_id INT NOT NULL DEFAULT 0,
        author_name VARCHAR(64) NOT NULL DEFAULT "",
        size DECIMAL(10,2) NOT NULL DEFAULT 0,
        download_url VARCHAR(255) NOT NULL DEFAULT "",
        youtube_video_id VARCHAR(32) NOT NULL DEFAULT "",
        youtube_channel_id VARCHAR(32) NOT NULL DEFAULT "",
        is_verified TINYINT NOT NULL DEFAULT 1
    )');
    $db->exec('CREATE TABLE levels (
        level_id BIGINT UNSIGNED PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(64) NOT NULL,
        description TEXT NOT NULL,
        level_data LONGTEXT NOT NULL,
        level_version INT NOT NULL DEFAULT 1,
        game_version INT NOT NULL DEFAULT 22,
        length INT NOT NULL DEFAULT 1,
        difficulty INT NOT NULL DEFAULT 30,
        downloads INT NOT NULL DEFAULT 5,
        likes INT NOT NULL DEFAULT 3,
        audio_track INT NOT NULL DEFAULT 0,
        demon INT NOT NULL DEFAULT 0,
        demon_difficulty INT NOT NULL DEFAULT 0,
        auto_level INT NOT NULL DEFAULT 0,
        stars INT NOT NULL DEFAULT 5,
        featured INT NOT NULL DEFAULT 0,
        epic INT NOT NULL DEFAULT 0,
        object_count INT NOT NULL DEFAULT 100,
        original_level_id BIGINT NOT NULL DEFAULT 0,
        two_player INT NOT NULL DEFAULT 0,
        coins INT NOT NULL DEFAULT 1,
        coins_verified INT NOT NULL DEFAULT 1,
        requested_stars INT NOT NULL DEFAULT 5,
        ldm INT NOT NULL DEFAULT 0,
        song_id BIGINT NOT NULL DEFAULT 0,
        is_deleted INT NOT NULL DEFAULT 0,
        is_unlisted INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $db->exec("INSERT INTO accounts VALUES (2, 'Creator')");
    $db->exec('INSERT INTO profiles VALUES (2,222)');
    $row = $db->prepare(
        'INSERT INTO levels(level_id,account_id,name,description,level_data,downloads)
         VALUES (:id,2,:name,:description,:body,:downloads)'
    );
    for ($i = 1; $i <= 12; $i++) {
        $row->execute([
            'id' => $i,
            'name' => 'Level ' . $i,
            'description' => 'Normal description',
            'body' => str_repeat('x', 262144),
            'downloads' => $i,
        ]);
    }
    $repo = new LevelRepository($db);
    $result = $repo->search(1, '', 22, 0, 10, 0, []);
    if ($result['total'] !== 12 || count($result['levels']) !== 10
        || (int)$result['levels'][0]['level_id'] !== 12) {
        throw new RuntimeException('Search ordering/total regression.');
    }
    foreach ($result['levels'] as $level) {
        if (array_key_exists('level_data', $level) || array_key_exists('level_info', $level)) {
            throw new RuntimeException('Full editor payload leaked into metadata listing.');
        }
        if ($level['description'] !== 'Normal description'
            || (int)$level['user_id'] !== 222) {
            throw new RuntimeException('Metadata projection missing required fields.');
        }
    }
    $wire = (new GdLevelListEncoder())->encode($result['levels'], 12, 0, 10, 22);
    if (!str_contains($wire, 'Level 12')
        || !str_contains($wire, 'Normal description')
        || !str_contains($wire, '#12:0:10#')) {
        throw new RuntimeException('Client list wire regressed after projection optimization.');
    }

    // Type=5 numeric search must retain profile join for count as well as list.
    $byUser = $repo->search(5, '222', 22, 0, 10, 0, []);
    if ($byUser['total'] !== 12 || count($byUser['levels']) !== 10) {
        throw new RuntimeException('User-ID search count changed when removing joins.');
    }
    echo "MUCHOCORE_LEVEL_LIST_PERF_OK\n";
} finally {
    $root->exec('DROP DATABASE IF EXISTS ' . $name);
}
