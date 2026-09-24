<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Compatibility/ClientVersion.php';
require __DIR__ . '/../../src/Protocol/GdCommentEncoder.php';
require __DIR__ . '/../../src/Protocol/GdHash.php';
require __DIR__ . '/../../src/Protocol/GdLevelDownloadEncoder.php';
require __DIR__ . '/../../src/Protocol/GdLevelListEncoder.php';
require __DIR__ . '/../../src/Protocol/GdLegacyText.php';
require __DIR__ . '/../../src/Protocol/GdXor.php';
require __DIR__ . '/../../src/Protocol/ProtocolText.php';

use MuchoCore\Compatibility\ClientVersion;
use MuchoCore\Protocol\GdCommentEncoder;
use MuchoCore\Protocol\GdHash;
use MuchoCore\Protocol\GdXor;
use MuchoCore\Protocol\GdLevelDownloadEncoder;
use MuchoCore\Protocol\GdLevelListEncoder;

function assertTrue(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$version = ClientVersion::fromValues(19, 21);

assertTrue($version->family() === '1.9', 'GD 1.9 family');
assertTrue($version->usesGjp2() === false, 'GD 1.9 uses legacy GJP');

assertTrue(
    MuchoCore\Protocol\GdLegacyText::encodeDescriptionForStorage(
        'Hello 1.9',
        19
    ) === 'SGVsbG8gMS45',
    '1.9 uploaded level description is stored as Base64'
);
assertTrue(
    MuchoCore\Protocol\GdLegacyText::decodeDescriptionForStorage(
        'SGVsbG8gMS45'
    ) === 'Hello 1.9',
    '1.9 level description update decodes Base64'
);

assertTrue(
    MuchoCore\Protocol\GdLegacyText::encodeDescriptionForResponse(
        'Hello 1.9',
        19
    ) === 'SGVsbG8gMS45',
    '1.9 level description response uses Base64'
);

assertTrue(
    MuchoCore\Protocol\GdLegacyText::decodeComment(
        'SGVsbG8gMS45',
        19
    ) === 'Hello 1.9',
    '1.9 incoming comment decodes Base64'
);

assertTrue(
    MuchoCore\Protocol\GdLegacyText::encodeCommentForResponse(
        'Hello 1.9',
        19
    ) === 'SGVsbG8gMS45',
    '1.9 comment response uses Base64'
);

$comments = new GdCommentEncoder();
$comment = $comments->encode(
    [
        'id' => 7,
        'account_id' => 9001,
        'content' => 'Hello 1.9',
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
    19,
    21
);

assertTrue(
    str_contains($comment, '2~SGVsbG8gMS45'),
    '1.9 comment wire payload is Base64'
);
assertTrue(
    !str_contains($comment, '~11~'),
    '1.9 comment uses legacy layout'
);
assertTrue(
    !str_contains($comment, ':1~MuchoPlayer'),
    '1.9 comment does not embed inline user payload'
);

$downloadEncoder = new GdLevelDownloadEncoder();
$download = $downloadEncoder->encode(
    [
        'level_id' => 123,
        'name' => 'Mucho 1.9',
        'description' => 'Hello 1.9',
        'level_data' => '1:2:3',
        'level_version' => 1,
        'user_id' => 42,
        'difficulty' => 20,
        'downloads' => 12,
        'audio_track' => 1,
        'game_version' => 19,
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
        'coins_verified' => 1,
        'requested_stars' => 7,
        'wt' => 0,
        'wt2' => 0,
        'settings_string' => '',
        'ldm' => 0,
        'copy_password' => '123',
        'song_ids' => '',
        'sfx_ids' => '',
        'ts' => 0,
    ],
    19
);

assertTrue(
    str_contains($download, ':3:Hello 1.9'),
    '1.9 downloaded level description is decoded text'
);
assertTrue(
    !str_contains($download, ':3:' . base64_encode('Hello 1.9')),
    '1.9 level download does not double-encode description'
);
assertTrue(
    str_contains($download, ':27:123'),
    '1.9 copy password remains un-XORed'
);
assertTrue(
    !str_contains($download, ':27:' . GdXor::copyPassword('123')),
    '1.9 does not use 2.x copy-password XOR'
);
assertTrue(
    str_contains(
        $download,
        '#' . GdHash::level('1:2:3') . '#' .
        GdHash::metadata('42,7,0,123,1,0,123,0')
    ),
    '1.9 level hashes use legacy salt and metadata'
);

$listEncoder = new GdLevelListEncoder();
$list = $listEncoder->encode(
    [[
        'level_id' => 123,
        'account_id' => 9001,
        'user_id' => 42,
        'username' => 'MuchoPlayer',
        'name' => 'Mucho 1.9',
        'level_version' => 1,
        'difficulty' => 20,
        'downloads' => 12,
        'audio_track' => 1,
        'game_version' => 19,
        'likes' => 5,
        'demon' => 0,
        'demon_difficulty' => 0,
        'auto_level' => 0,
        'stars' => 7,
        'featured' => 0,
        'epic' => 0,
        'object_count' => 100,
        'description' => 'Hello 1.9',
        'length' => 2,
        'original_level_id' => 0,
        'two_player' => 0,
        'coins' => 3,
        'coins_verified' => 1,
        'requested_stars' => 7,
        'ldm' => 0,
        'song_id' => 12345,
        'song_protocol_id' => 12345,
        'song_name' => 'Legacy Song',
        'song_author_id' => 7,
        'song_author_name' => 'Artist',
        'song_size' => 3.14,
        'song_download_url' => 'https://example.invalid/song.mp3',
        'song_youtube_video_id' => '',
        'song_youtube_channel_id' => '',
    ]],
    1,
    0,
    10,
    19
);

assertTrue(
    str_contains($list, ':3:' . base64_encode('Hello 1.9') . ':15:'),
    '1.9 level list description is Base64'
);
assertTrue(
    str_contains($list, '#42:MuchoPlayer:9001#1~|~12345'),
    '1.9 level list contains user and song sections'
);
$expectedMultiHash = sha1('1373' . 'xI25fpAapCQg');
$actualMultiHash = substr($list, -40);

assertTrue(
    $actualMultiHash === $expectedMultiHash,
    '1.9 level list multi-level hash: expected ' .
    $expectedMultiHash . ', got ' . $actualMultiHash
);

echo "MUCHOCORE_PROTOCOL_19_WIRE_OK\n";
