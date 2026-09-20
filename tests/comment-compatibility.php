<?php

declare(strict_types=1);

/* Copyright (C) 2026 IZK */

use MuchoCore\Protocol\GdCommentEncoder;

require dirname(__DIR__) . '/vendor/autoload.php';

$encoder = new GdCommentEncoder();

$comment = [
    'content' => 'Hello GD',
    'account_id' => 7,
    'likes' => 0,
    'is_spam' => 0,
    'created_at' => date('Y-m-d H:i:s'),
    'percent' => 42,
    'id' => 99,
];

$profile = [
    'username' => 'Player',
    'cube' => 1,
    'color1' => 0,
    'color2' => 3,
    'special' => 0,
    'badge' => 0,
];

$legacy = $encoder->encode(
    $comment,
    $profile,
    19
);

if (!str_contains($legacy, '2~SGVsbG8gR0Q=')) {
    throw new RuntimeException(
        'Legacy level comment must use Base64 payload.'
    );
}

$modern = $encoder->encode(
    $comment,
    $profile,
    20
);

if (!str_contains($modern, '2~Hello GD')) {
    throw new RuntimeException(
        'Modern level comment must use raw text payload.'
    );
}

$account = $encoder->encodeAccountComment($comment);

if (!str_contains($account, '2~Hello GD')) {
    throw new RuntimeException(
        'Account comment must use raw text payload.'
    );
}

echo "COMMENT_COMPATIBILITY_OK\n";
