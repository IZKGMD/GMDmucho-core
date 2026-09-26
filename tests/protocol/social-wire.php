<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Protocol/ProtocolText.php';
require __DIR__ . '/../../src/Protocol/GdMessageEncoder.php';
require __DIR__ . '/../../src/Protocol/GdRelationshipEncoder.php';

use MuchoCore\Protocol\GdMessageEncoder;
use MuchoCore\Protocol\GdRelationshipEncoder;

function assertSameValue(mixed $expected, mixed $actual, string $name): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL {$name}: expected " . var_export($expected, true) .
            ", got " . var_export($actual, true) . "\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$messageEncoder = new GdMessageEncoder();

$unread = $messageEncoder->encodeMessage([
    'id' => 10,
    'user_id' => 20,
    'account_id' => 30,
    'username' => 'Sender',
    'subject' => 'Hello',
    'is_read' => 0,
    'created_at' => '2026-01-01 12:00:00',
    'body' => 'Body',
]);

assertSameValue(
    true,
    str_contains($unread, ':8:0:'),
    'unread message maps to legacy isNew=0'
);

$read = $messageEncoder->encodeMessage([
    'id' => 11,
    'user_id' => 21,
    'account_id' => 31,
    'username' => 'Sender',
    'subject' => 'Read',
    'is_read' => 1,
    'created_at' => '2026-01-01 12:00:00',
]);

assertSameValue(
    true,
    str_contains($read, ':8:1:'),
    'read message maps to isNew=0'
);

$relationship = new GdRelationshipEncoder();
$users = $relationship->users([[
    'account_id' => 30,
    'user_id' => 20,
    'username' => 'Friend',
    'cube' => 3,
    'color1' => 2,
    'color2' => 4,
    'special' => 0,
    'icon_type' => 0,
    'is_new' => 1,
]]);

assertSameValue(
    true,
    str_contains($users, ':18:0:41:1'),
    'friend user payload exposes is_new state'
);

$clanUsers = $relationship->users([[
    'account_id' => 31,
    'user_id' => 21,
    'username' => 'LongPlayerName',
    'clan_tag' => 'MUCH',
    'cube' => 3,
    'color1' => 2,
    'color2' => 4,
    'special' => 0,
    'icon_type' => 0,
    'is_new' => 0,
]]);

assertSameValue(
    true,
    str_contains($clanUsers, '1:[MUCH]LongPlayerNa'),
    'friend user payload includes clan prefix'
);

echo "MUCHOCORE_SOCIAL_WIRE_OK\n";
