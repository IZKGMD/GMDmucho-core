<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Protocol\Gd2209ThreadWire;
use MuchoCore\Protocol\GdRelationshipEncoder;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('GD2.209 thread wire: ' . $message);
    }
};
$rows = [[
    'id'=>589952, 'thread_id'=>9, 'sender_account_id'=>14054365,
    'body'=>'hello', 'created_at'=>'2026-10-18 12:00:00 UTC'
]];
$expected = '589952:14054365:aGVsbG8=:1792324800:0';
$assert(Gd2209ThreadWire::message($rows[0]) === $expected,
    'must emit five positional fields, not key/value fields');
$assert(Gd2209ThreadWire::messages([]) === 'none',
    'native empty message list marker is none');
$assert(Gd2209ThreadWire::messages($rows) === $expected, 'one real message');
$reference = [
    'thread'=>['id'=>9,'owner_account_id'=>11,'title'=>'My group'],
    'messages'=>$rows,
    'members'=>[[
        'account_id'=>11, 'username'=>'Owner', 'user_id'=>100,
        'cube'=>2,'color1'=>1,'color2'=>3,'special'=>0
    ]],
    'invites'=>[]
];
$envelope = Gd2209ThreadWire::snapshot($reference,new GdRelationshipEncoder());
$segments = explode('#',$envelope);
$assert(count($segments) === 7, 'exactly seven native segments');
$assert($segments[0] === $expected, 'messages segment uses native object');
$assert(str_contains($segments[1], '1:Owner:2:100'), 'real member segment');
$assert($segments[2] === '' && $segments[3] === '',
    'unknown historical and empty pending invites have empty sections');
$assert(str_starts_with($segments[4], '11:0:2:'),
    'native metadata positional group prefix');
$assert($segments[5] === '0' && $segments[6] === '3,1,10,50',
    'native last-message and tail constants');
$inviteUser = Gd2209ThreadWire::invites([[
    'invitee_account_id'=>23, 'user_id'=>57, 'username'=>'Join me',
    'inviter_account_id'=>11,'cube'=>2,'color1'=>3,'color2'=>4,'special'=>1,
]]);
$inviteFields = explode(':',$inviteUser);
$assert(count($inviteFields) === 17, 'native invited user has 17 positional values');
$assert($inviteFields[0] === '23' && $inviteFields[1] === '57'
    && $inviteFields[2] === base64_encode('Join me')
    && $inviteFields[3] === '11', 'identity fields for invited player');

$reference['messages'] = [];
$assert(str_starts_with(Gd2209ThreadWire::snapshot(
    $reference, new GdRelationshipEncoder()
), 'none#'), 'zero messages is a valid seven-section envelope');

echo "MUCHOCORE_GD2209_THREAD_WIRE_OK\n";
