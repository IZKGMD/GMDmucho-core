<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Compatibility/ClientVersion.php';
require __DIR__ . '/../../src/Protocol/GdCommentEncoder.php';
require __DIR__ . '/../../src/Protocol/GdHash.php';
require __DIR__ . '/../../src/Protocol/GdLevelDownloadEncoder.php';
require __DIR__ . '/../../src/Protocol/GdLevelListEncoder.php';
require __DIR__ . '/../../src/Protocol/GdUserEncoder.php';
require __DIR__ . '/../../src/Protocol/GdXor.php';

use MuchoCore\Compatibility\ClientVersion;
use MuchoCore\Protocol\GdCommentEncoder;
use MuchoCore\Protocol\GdHash;
use MuchoCore\Protocol\GdLevelDownloadEncoder;
use MuchoCore\Protocol\GdLevelListEncoder;
use MuchoCore\Protocol\GdUserEncoder;
use MuchoCore\Protocol\GdXor;

function assertTrue(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$version = ClientVersion::fromValues(21, 35);
assertTrue($version->family() === '2.1', 'GD 2.1 family');
assertTrue($version->usesGjp2() === false, 'GD 2.1 uses legacy GJP');
assertTrue(
    ClientVersion::fromValues(20, 28)->family() === '2.1',
    'GD 2.0 binary 28 maps to 2.1'
);

$userEncoder = new GdUserEncoder();
$profile = $userEncoder->profile([
    'account_id' => 9001,
    'user_id' => 42,
    'username' => 'MuchoPlayer',
    'stars' => 100,
    'demons' => 5,
    'creator_points' => 12,
    'color1' => 3,
    'color2' => 4,
    'color3' => 5,
    'icon_id' => 6,
    'icon_type' => 0,
    'special' => 1,
    'glow' => 7,
    'demon_info' => '1,2,3',
    'star_info' => '4,5,6',
    'platformer_info' => '7,8,9',
]);
assertTrue(str_contains($profile, '51:5'), '2.1 profile color3 field');
assertTrue(str_contains($profile, '55:1,2,3'), '2.1 profile demon info');
assertTrue(str_contains($profile, '56:4,5,6'), '2.1 profile star info');
assertTrue(str_contains($profile, '57:7,8,9'), '2.1 profile platformer info');

$comments = new GdCommentEncoder();
$legacyComment = $comments->encode(
    [
        'id' => 7,
        'account_id' => 9001,
        'content' => 'Hello 2.1',
        'likes' => 1,
        'is_spam' => 0,
        'percent' => 55,
        'created_at' => '2026-01-01 12:00:00',
    ],
    [
        'account_id' => 9001,
        'username' => 'MuchoPlayer',
        'cube' => 6,
        'color1' => 3,
        'color2' => 4,
        'special' => 1,
        'icon_type' => 0,
        'badge' => 0,
    ],
    21,
    31
);
assertTrue(!str_contains($legacyComment, '~11~'), '2.1 binary 31 uses legacy comment layout');

$modernComment = $comments->encode(
    [
        'id' => 7,
        'account_id' => 9001,
        'content' => 'Hello 2.1',
        'likes' => 1,
        'is_spam' => 0,
        'percent' => 55,
        'created_at' => '2026-01-01 12:00:00',
    ],
    [
        'account_id' => 9001,
        'username' => 'MuchoPlayer',
        'cube' => 6,
        'color1' => 3,
        'color2' => 4,
        'special' => 1,
        'icon_type' => 0,
        'badge' => 0,
    ],
    21,
    35
);
assertTrue(str_contains($modernComment, '~11~0'), '2.1 binary 35 embeds mod badge');
assertTrue(str_contains($modernComment, ':1~MuchoPlayer'), '2.1 binary 35 embeds user payload');

$levelEncoder = new GdLevelDownloadEncoder();
$levelData = '1:2:3';
$download = $levelEncoder->encode([
    'level_id' => 123,
    'name' => 'Mucho 2.1',
    'description' => 'encoded-description',
    'level_data' => $levelData,
    'level_version' => 1,
    'user_id' => 42,
    'difficulty' => 10,
    'downloads' => 12,
    'audio_track' => 1,
    'game_version' => 21,
    'likes' => 5,
    'demon' => 0,
    'demon_difficulty' => 0,
    'auto_level' => 0,
    'stars' => 7,
    'featured' => 0,
    'epic' => 0,
    'object_count' => 100,
    'length' => 2,
    'original_level_id' => 0,
    'two_player' => 0,
    'created_at' => '2026-01-01 12:00:00',
    'updated_at' => '2026-01-02 12:00:00',
    'song_id' => 0,
    'extra_string' => '29_29',
    'coins' => 3,
    'coins_verified' => 3,
    'requested_stars' => 7,
    'wt' => 0,
    'wt2' => 0,
    'settings_string' => '',
    'ldm' => 0,
    'copy_password' => '123',
    'song_ids' => '',
    'sfx_ids' => '',
    'ts' => 0,
], 21);

assertTrue(
    str_contains($download, ':27:' . GdXor::copyPassword('123')),
    '2.1 copy password uses XOR/base64'
);
assertTrue(
    str_contains($download, ':28:01-01-2026 12-00:29:02-01-2026 12-00'),
    '2.1 level dates use protocol format'
);
assertTrue(
    str_contains(
        $download,
        '#'.GdHash::level($levelData).'#'.GdHash::metadata('42,7,0,123,3,0,123,0')
    ),
    '2.1 level hashes match protocol inputs'
);

$listEncoder = new GdLevelListEncoder();
$list = $listEncoder->encode(
    [[
        'level_id' => 123,
        'account_id' => 9001,
        'user_id' => 42,
        'username' => 'MuchoPlayer',
        'name' => 'Mucho 2.1',
        'level_version' => 1,
        'difficulty' => 10,
        'downloads' => 12,
        'audio_track' => 1,
        'game_version' => 21,
        'likes' => 5,
        'demon' => 0,
        'demon_difficulty' => 0,
        'auto_level' => 0,
        'stars' => 7,
        'featured' => 0,
        'epic' => 0,
        'object_count' => 100,
        'description' => 'encoded-description',
        'length' => 2,
        'original_level_id' => 0,
        'two_player' => 0,
        'coins' => 3,
        'coins_verified' => 3,
        'requested_stars' => 7,
        'ldm' => 0,
        'song_id' => 0,
    ]],
    1,
    0,
    10,
    21
);
assertTrue(str_starts_with($list, '1:123:2:Mucho 2.1'), '2.1 level list wire fields');
assertTrue(str_contains($list, '#42:MuchoPlayer:9001'), '2.1 level list user section');
assertTrue(str_ends_with($list, '#'.sha1('1337' . 'xI25fpAapCQg')), '2.1 level list hash');
assertTrue(substr_count($list, '|') === 0, 'single level list entry has no trailing pipe');

echo "MUCHOCORE_PROTOCOL_21_WIRE_OK\n";
