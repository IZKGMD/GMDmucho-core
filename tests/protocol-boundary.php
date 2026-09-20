<?php

declare(strict_types=1);

/* Copyright (C) 2026 IZK */

require dirname(__DIR__) . '/src/Protocol/ProtocolText.php';
require dirname(__DIR__) . '/src/Protocol/GdLevelListEncoder.php';
require dirname(__DIR__) . '/src/Protocol/GdMessageEncoder.php';
require dirname(__DIR__) . '/src/Protocol/GdRelationshipEncoder.php';
require dirname(__DIR__) . '/src/Protocol/GdSongEncoder.php';

use MuchoCore\Protocol\GdLevelListEncoder;
use MuchoCore\Protocol\GdMessageEncoder;
use MuchoCore\Protocol\GdRelationshipEncoder;
use MuchoCore\Protocol\GdSongEncoder;
use MuchoCore\Protocol\ProtocolText;

if (ProtocolText::field("a:b|c#d~e") !== 'abcde') {
    throw new RuntimeException('Generic GD field sanitization failed.');
}

if (ProtocolText::comment("a~b|c#d\ne") !== 'abcde') {
    throw new RuntimeException('GD comment sanitization failed.');
}

$levels = [[
    'level_id' => 12345,
    'account_id' => 7,
    'user_id' => 42,
    'username' => 'Player',
    'name' => 'Level:Broken|Name#',
    'level_version' => 1,
    'difficulty' => 3,
    'downloads' => 1,
    'audio_track' => 0,
    'game_version' => 22,
    'likes' => 0,
    'demon' => 0,
    'demon_difficulty' => 0,
    'auto_level' => 0,
    'stars' => 5,
    'featured' => 0,
    'epic' => 0,
    'object_count' => 10,
    'description' => 'Description:Broken|',
    'length' => 2,
    'original_level_id' => 0,
    'two_player' => 0,
    'coins' => 0,
    'coins_verified' => 0,
    'requested_stars' => 5,
    'ldm' => 0,
    'song_id' => 0,
]];

$list = (new GdLevelListEncoder())->encode($levels, 1, 0, 10, 22);

if (str_contains($list, 'Level:Broken') || str_contains($list, 'Description:Broken')) {
    throw new RuntimeException('Level protocol still contains unsafe delimiters.');
}

$message = (new GdMessageEncoder())->encodeMessage([
    'id' => 1,
    'to_account_id' => 2,
    'account_id' => 2,
    'to_user_id' => 3,
    'user_id' => 3,
    'to_username' => 'Receiver',
    'username' => 'Sender',
    'subject' => 'Hello:|#',
    'body' => 'Body:|#',
    'is_read' => 0,
    'created_at' => date('Y-m-d H:i:s'),
]);

if (
    str_contains($message, ':4:Hello:') ||
    str_contains($message, ':5:Body:')
) {
    throw new RuntimeException(
        'Message protocol still contains unsafe delimiters.'
    );
}

$relationship = (new GdRelationshipEncoder())->requests([[
    'to_account_id' => 2,
    'account_id' => 1,
    'user_id' => 3,
    'username' => 'Receiver',
    'cube' => 1,
    'color1' => 0,
    'color2' => 3,
    'special' => 0,
    'id' => 5,
    'comment' => 'Hello:|#',
    'is_read' => 0,
    'created_at' => date('Y-m-d H:i:s'),
]], 1, 0, false);

if (str_contains($relationship, 'Hello:')) {
    throw new RuntimeException('Friend request protocol still contains unsafe delimiters.');
}

$song = (new GdSongEncoder())->encode([
    'id' => 10,
    'name' => 'Song~|~Name',
    'author_id' => 20,
    'author_name' => 'Artist#Name',
    'size' => 1.2,
    'download_url' => 'https://example.com/song.mp3',
    'youtube_video_id' => '',
    'youtube_channel_id' => '',
    'is_verified' => 1,
]);

if (str_contains($song, 'Song~|~Name')) {
    throw new RuntimeException('Song protocol still contains unsafe delimiters.');
}

echo "PROTOCOL_BOUNDARY_OK\n";
