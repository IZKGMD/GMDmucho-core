<?php

declare(strict_types=1);

/* Copyright (C) 2026 IZK */

require dirname(__DIR__) . '/src/Protocol/ProtocolText.php';
require dirname(__DIR__) . '/src/Protocol/GdLevelListEncoder.php';

use MuchoCore\Protocol\GdLevelListEncoder;

$encoder = new GdLevelListEncoder();

$levels = [[
    'level_id' => 12345,
    'account_id' => 7,
    'user_id' => 42,
    'username' => 'Player',
    'name' => 'Test Level',
    'level_version' => 1,
    'difficulty' => 3,
    'downloads' => 12,
    'audio_track' => 0,
    'game_version' => 18,
    'likes' => 4,
    'demon' => 0,
    'demon_difficulty' => 0,
    'auto_level' => 0,
    'stars' => 0,
    'featured' => 0,
    'epic' => 0,
    'object_count' => 123,
    'description' => '',
    'length' => 2,
    'original_level_id' => 0,
    'two_player' => 0,
    'coins' => 0,
    'coins_verified' => 0,
    'requested_stars' => 0,
    'ldm' => 0,
    'song_id' => 0,
]];
$legacy = $encoder->encode($levels, 1, 0, 10, 18);
if (substr_count($legacy, '#') !== 3) {
    throw new RuntimeException('GD 1.x level-list response must have 4 sections.');
}
$modern = $encoder->encode($levels, 1, 0, 10, 20);
if (substr_count($modern, '#') !== 4) {
    throw new RuntimeException('GD 1.9+ level-list response must have 5 sections.');
}
echo "LEVEL_LIST_SHAPE_COMPATIBILITY_OK\n";
