<?php

declare(strict_types=1);

/* Copyright (C) 2026 IZK */

require dirname(__DIR__) . '/src/Protocol/ProtocolText.php';
require dirname(__DIR__) . '/src/Protocol/GdUserEncoder.php';

use MuchoCore\Protocol\GdUserEncoder;

$encoder = new GdUserEncoder();

$profile = $encoder->profile([
    'account_id' => 900,
    'user_id' => 42,
    'username' => 'Player',
    'stars' => 123,
    'demons' => 4,
    'secret_coins' => 5,
    'user_coins' => 6,
    'diamonds' => 7,
    'moons' => 8,
    'creator_points' => 9,
    'cube' => 10,
    'ship' => 11,
    'ball' => 12,
    'ufo' => 13,
    'wave' => 14,
    'robot' => 15,
    'spider' => 16,
    'swing' => 17,
    'jetpack' => 18,
    'explosion' => 19,
    'color1' => 1,
    'color2' => 2,
    'color3' => 3,
    'icon_type' => 4,
]);

if (!str_contains($profile, '2:42:')) {
    throw new RuntimeException('Profile must expose userID at field 2.');
}

if (!str_contains($profile, '16:900')) {
    throw new RuntimeException('Profile must expose account/ext ID at field 16.');
}

$search = $encoder->search(
    [[
        'account_id' => 900,
        'user_id' => 42,
        'username' => 'Player',
        'stars' => 123,
        'demons' => 4,
        'secret_coins' => 5,
        'user_coins' => 6,
        'diamonds' => 7,
        'moons' => 8,
        'creator_points' => 9,
        'cube' => 10,
        'icon_type' => 4,
        'color1' => 1,
        'color2' => 2,
        'color3' => 3,
        'special' => 0,
    ]],
    1,
    0,
    10
);

if (!str_contains($search, '2:42:')) {
    throw new RuntimeException('Search must expose userID at field 2.');
}

if (!str_contains($search, '16:900')) {
    throw new RuntimeException('Search must expose account/ext ID at field 16.');
}

echo "USER_PROTOCOL_COMPATIBILITY_OK\n";
