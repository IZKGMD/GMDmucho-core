<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Compatibility/ClientVersion.php';
require __DIR__ . '/../../src/Protocol/GdCommentEncoder.php';
require __DIR__ . '/../../src/Protocol/GdHash.php';
require __DIR__ . '/../../src/Protocol/GdLevelDownloadEncoder.php';
require __DIR__ . '/../../src/Protocol/GdLevelListEncoder.php';
require __DIR__ . '/../../src/Protocol/GdUserEncoder.php';
require __DIR__ . '/../../src/Protocol/GdXor.php';
require __DIR__ . '/../../src/Protocol/GdLegacyText.php';
require __DIR__ . '/../../src/Protocol/ProtocolText.php';
require __DIR__ . '/../../src/User/GameRole.php';

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

$version = ClientVersion::fromValues(20, 27);
assertTrue($version->family() === '2.0', 'GD 2.0 family');
assertTrue($version->usesGjp2() === false, 'GD 2.0 uses legacy GJP');

assertTrue(
    \MuchoCore\Protocol\GdLegacyText::encodeDescriptionForResponse(
        'Hello 2.0',
        20
    ) === 'SGVsbG8gMi4w',
    '2.0 description response uses Base64'
);

assertTrue(
    \MuchoCore\Protocol\GdLegacyText::decodeComment(
        'Hello 2.0',
        20
    ) === 'Hello 2.0',
    '2.0 incoming comment remains plain text'
);

assertTrue(
    \MuchoCore\Protocol\GdLegacyText::encodeCommentForResponse(
        'Hello 2.0',
        20
    ) === 'Hello 2.0',
    '2.0 comment response remains plain text'
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
]);

assertTrue(str_contains($profile, '1:MuchoPlayer'), '2.0 profile username');
assertTrue(str_contains($profile, '2:42'), '2.0 profile user id');
assertTrue(str_contains($profile, '3:100'), '2.0 profile stars');
assertTrue(str_contains($profile, '4:5'), '2.0 profile demons');
assertTrue(!str_contains($profile, '7:9001'), '2.0 profile omits leaderboard-only account field');
assertTrue(str_contains($profile, '51:5'), '2.0 profile color3');

$comments = new GdCommentEncoder();
$comment = $comments->encode(
    [
        'id' => 7,
        'account_id' => 9001,
        'content' => 'Hello 2.0',
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
    20,
    27
);

assertTrue(str_contains($comment, '2~Hello 2.0'), '2.0 comment wire text');
assertTrue(!str_contains($comment, '~11~'), '2.0 legacy comment layout');
assertTrue(!str_contains($comment, ':'), '2.0 comment has no inline user payload');

$accountComment = $comments->encodeAccountComment(
    [
        'id' => 8,
        'account_id' => 9001,
        'content' => 'Profile hello',
        'likes' => 2,
        'is_spam' => 0,
        'created_at' => '2026-01-01 12:00:00',
    ],
    20
);

assertTrue(
    str_contains($accountComment, '2~Profile hello'),
    '2.0 account comment wire text'
);

$levelEncoder = new GdLevelDownloadEncoder();
$download = $levelEncoder->encode([
    'level_id' => 123,
    'name' => 'Mucho 2.0',
    'description' => 'encoded-description',
    'level_data' => '1:2:3',
    'level_version' => 1,
    'user_id' => 42,
    'difficulty' => 10,
    'downloads' => 12,
    'audio_track' => 1,
    'game_version' => 20,
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
], 20);

assertTrue(
    str_contains($download, ':27:' . GdXor::copyPassword('123')),
    '2.0 copy password uses XOR/base64'
);
assertTrue(
    str_contains($download, ':3:' . base64_encode('encoded-description')),
    '2.0 level download encodes description'
);
assertTrue(
    str_contains($download, ':28:01-01-2026 12-00:29:02-01-2026 12-00'),
    '2.0 level dates use protocol format'
);
assertTrue(
    str_contains(
        $download,
        '#' . GdHash::level('1:2:3') . '#' .
        GdHash::metadata('42,7,0,123,3,0,123,0')
    ),
    '2.0 level hashes match protocol inputs'
);

$listEncoder = new GdLevelListEncoder();
$list = $listEncoder->encode(
    [[
        'level_id' => 123,
        'account_id' => 9001,
        'user_id' => 42,
        'username' => 'MuchoPlayer',
        'name' => 'Mucho 2.0',
        'level_version' => 1,
        'difficulty' => 10,
        'downloads' => 12,
        'audio_track' => 1,
        'game_version' => 20,
        'likes' => 5,
        'demon' => 0,
        'demon_difficulty' => 0,
        'auto_level' => 0,
        'stars' => 15,
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
    20
);

assertTrue(str_contains($list, ':3:' . base64_encode('encoded-description') . ':15:'), '2.0 list description');
assertTrue(str_contains($list, '#42:MuchoPlayer:9001'), '2.0 list user section');
assertTrue(
    str_ends_with($list, '#' . sha1('13153' . 'xI25fpAapCQg')),
    '2.0 list multi-level hash'
);
assertTrue(substr_count($list, '|') === 0, '2.0 single list has no trailing pipe');

echo "MUCHOCORE_PROTOCOL_20_WIRE_OK\n";
