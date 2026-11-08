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

$messageSubject = 'MuchoCore: Subject #|~ Привет';
$messageBody = 'MuchoCore: Body #|~ -4 Привет 😀';
$messageSubjectWire = $messageEncoder->encodeSubject($messageSubject);
$messageBodyWire = $messageEncoder->encodeBody($messageBody);

assertSameValue(
    'TXVjaG9Db3JlOiBTdWJqZWN0ICN8fiDQn9GA0LjQstC10YI=',
    $messageSubjectWire,
    'GDFormat reference subject encoding'
);
assertSameValue(
    'fEFRXV5yW0BQCxF2XVFIERdOSxEcABLlruC04o3hg-SH5LMRxK2tsQ==',
    $messageBodyWire,
    'GDFormat reference XOR message-body encoding'
);
assertSameValue(
    'MuchoCore: Subject #|~ Привет',
    $messageEncoder->decodeSubject('TXVjaG9Db3JlOiBTdWJqZWN0ICN8fiDQn9GA0LjQstC10YI='),
    'decode GDFormat reference subject vector'
);
assertSameValue(
    'MuchoCore: Body #|~ -4 Привет 😀',
    $messageEncoder->decodeBody('fEFRXV5yW0BQCxF2XVFIERdOSxEcABLlruC04o3hg-SH5LMRxK2tsQ=='),
    'decode GDFormat reference body vector'
);

assertSameValue(
    $messageSubject,
    $messageEncoder->decodeSubject($messageSubjectWire),
    'GD 2.2 subject Base64 round trip with Unicode and protocol delimiters'
);
assertSameValue(
    $messageBody,
    $messageEncoder->decodeBody($messageBodyWire),
    'GD 2.2 body XOR/Base64 round trip with Unicode and protocol delimiters'
);
assertSameValue(null, $messageEncoder->decodeSubject('%%%'), 'reject malformed subject Base64');
assertSameValue(null, $messageEncoder->decodeBody('%%%'), 'reject malformed body Base64');

$encodedMessage = $messageEncoder->encodeMessage([
    'id' => 12,
    'user_id' => 22,
    'account_id' => 32,
    'username' => 'Sender',
    'subject' => $messageSubject,
    'content_format' => 1,
    'is_read' => 0,
    'created_at' => '2026-01-01 12:00:00',
    'body' => $messageBody,
]);
assertSameValue(
    true,
    str_contains($encodedMessage, ':4:' . $messageSubjectWire . ':8:'),
    'message wire encodes subject before joining protocol fields'
);
assertSameValue(
    true,
    str_contains($encodedMessage, ':5:' . $messageBodyWire . ':7:'),
    'message wire XOR-encodes body before joining protocol fields'
);

$unread = $messageEncoder->encodeMessage([
    'id' => 10,
    'user_id' => 20,
    'account_id' => 30,
    'username' => 'Sender',
    'subject' => 'Hello',
    'content_format' => 1,
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
    'content_format' => 1,
    'is_read' => 1,
    'created_at' => '2026-01-01 12:00:00',
]);

assertSameValue(
    true,
    str_contains($read, ':8:1:'),
    'read message maps to isNew=0'
);


$legacySubjectWire = $messageEncoder->encodeSubject('Legacy subject');
$legacyBodyWire = $messageEncoder->encodeBody('Legacy body');
$legacyMessage = $messageEncoder->encodeMessage([
    'id' => 13,
    'user_id' => 23,
    'account_id' => 33,
    'username' => 'Sender',
    'subject' => $legacySubjectWire,
    'content_format' => 0,
    'is_read' => 0,
    'created_at' => '2026-01-01 12:00:00',
    'body' => $legacyBodyWire,
]);
assertSameValue(
    true,
    str_contains($legacyMessage, ':4:' . $legacySubjectWire . ':8:'),
    'legacy wire-format subject is passed through without double encoding'
);
assertSameValue(
    true,
    str_contains($legacyMessage, ':5:' . $legacyBodyWire . ':7:'),
    'legacy wire-format body is passed through without double encoding'
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
