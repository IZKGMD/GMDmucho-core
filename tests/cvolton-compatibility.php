<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MuchoCore\Protocol\GdHash;
use MuchoCore\Protocol\GdLevelDownloadEncoder;
use MuchoCore\Protocol\GdLevelListEncoder;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }

    echo "[PASS] {$message}\n";
}

$payload = 'compatibility-sample';
check(
    GdHash::level($payload) === sha1(
        $payload . 'xI25fpAapCQg'
    ),
    'solo GD level hash uses the reference salt'
);

$levels = [[
    'level_id' => 12345,
    'account_id' => 42,
    'user_id' => 77,
    'username' => 'CompatTester',
    'name' => 'Reference',
    'level_version' => 3,
    'difficulty' => 20,
    'downloads' => 12,
    'audio_track' => 0,
    'game_version' => 22,
    'likes' => 9,
    'demon' => 0,
    'demon_difficulty' => 0,
    'auto_level' => 0,
    'stars' => 4,
    'featured' => 1,
    'epic' => 0,
    'object_count' => 5000,
    'description' => 'VGVzdA==',
    'length' => 2,
    'original_level_id' => 0,
    'two_player' => 0,
    'coins' => 3,
    'coins_verified' => 1,
    'requested_stars' => 6,
    'ldm' => 0,
    'song_id' => 0,
    'song_row_id' => 9001,
    'song_name' => 'Custom Song',
    'song_author_id' => 12,
    'song_author_name' => 'Composer',
    'song_size' => 2.5,
    'song_download_url' => 'https://example.test/song.mp3',
    'song_is_verified' => 1,
]];

$list = (new GdLevelListEncoder())->encode(
    $levels,
    1,
    0,
    10,
    22
);

$parts = explode('#', $list);

check(
    count($parts) === 5,
    'modern getGJLevels response has 5 protocol sections'
);

check(
    str_contains($parts[2], '1~|~9001~|~2~|~Custom Song'),
    'modern getGJLevels includes custom song payload'
);

$expectedMultiHash = sha1(
    '1541' . 'xI25fpAapCQg'
);

check(
    hash_equals($expectedMultiHash, $parts[4]),
    'getGJLevels multi-level hash matches reference algorithm'
);

$legacy = (new GdLevelListEncoder())->encode(
    $levels,
    1,
    0,
    10,
    18
);

check(
    count(explode('#', $legacy)) === 4,
    'legacy getGJLevels response keeps hash without song section'
);

$level = [
    'level_id' => 900,
    'name' => 'Download',
    'description' => 'VGVzdA==',
    'level_data' => 'kS1TESTDATA',
    'level_version' => 1,
    'user_id' => 77,
    'difficulty' => 20,
    'downloads' => 3,
    'audio_track' => 0,
    'game_version' => 22,
    'likes' => 4,
    'demon' => 0,
    'demon_difficulty' => 0,
    'auto_level' => 0,
    'stars' => 4,
    'featured' => 0,
    'epic' => 0,
    'object_count' => 100,
    'length' => 1,
    'original_level_id' => 0,
    'two_player' => 0,
    'created_at' => '2026-01-01 00:00:00',
    'updated_at' => '2026-01-01 00:00:00',
    'song_id' => 9001,
    'extra_string' => '',
    'coins' => 0,
    'coins_verified' => 0,
    'requested_stars' => 0,
    'wt' => 0,
    'wt2' => 0,
    'settings_string' => '',
    'ldm' => 0,
    'copy_password' => '0',
    'song_ids' => '9001',
    'sfx_ids' => '',
    'ts' => 0,
    'level_info' => '',
];

$download = (new GdLevelDownloadEncoder())->encode(
    $level,
    22,
    true
);

check(
    str_contains($download, ':26:'),
    'downloadGJLevel extras payload is present'
);

check(
    str_contains($download, ':52:9001'),
    'downloadGJLevel includes song IDs'
);

check(
    substr_count($download, '#') === 2,
    'downloadGJLevel keeps the two-hash response shape'
);

echo "[OK] Cvolton compatibility protocol checks passed.\n";
