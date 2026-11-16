<?php

declare(strict_types=1);

// No database or credentials: native GD 2.209 notification framing regression.
require dirname(__DIR__, 2) . '/src/Protocol/ProtocolText.php';
require dirname(__DIR__, 2) . '/src/Social/NotificationService.php';

use MuchoCore\Social\NotificationService;

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('GD 2.209 notification: ' . $message);
    }
};
$parse = static function (string $wire) use ($check): array {
    $parts = explode(':', $wire);
    $check(count($parts) === 32, '16 exact key/value pairs');
    $fields = [];
    foreach (array_chunk($parts, 2) as [$key, $value]) {
        $fields[(int)$key] = $value;
    }
    $check(array_keys($fields) === range(1, 16), 'all keys in documented order');
    return $fields;
};

$row = [
    'id' => 77,
    'notification_type' => 'follow',
    'created_at' => '2026-11-16 13:22:00 +00:00',
    'username' => 'IZK:|~',
    'actor_account_id' => 12,
    'source_id' => 9999, // Must NOT become the attached level ID.
    'is_read' => 0,
    'reward_claimed' => 0,
];
$now = strtotime('2026-11-16 13:24:00 +00:00');
$wire = NotificationService::encodeNotification($row, $now);
$fields = $parse($wire);

$check($fields[1] === '77', 'notification identity');
$check($fields[2] === '13', 'follower event type, not string in field 5');
$check($fields[3] === '', 'no fabricated emote icon');
$check(base64_decode(strtr($fields[4], '-_', '+/'), true) === 'New follower',
    'title uses Base64');
$check(base64_decode(strtr($fields[5], '-_', '+/'), true) === 'IZK started following you.',
    'description uses Base64 and sanitizes username');
$check($fields[6] === '2 minutes', 'age is human-readable, without ago suffix');
$check($fields[7] === '1', 'unread means isNew');
$check($fields[8] === '0' && $fields[9] === '0',
    'non-level follow event must not link arbitrary source_id');
$check($fields[10] === '' && $fields[11] === '0' && $fields[12] === '0' &&
    $fields[13] === '0' && $fields[14] === '0', 'no fabricated rewards or chests');
$check($fields[15] === '12' && $fields[16] === 'IZK', 'actor identity matches');
$check(!str_contains($wire, '~') && !str_contains($wire, '|') && !str_contains($wire, '#'),
    'wire delimiters remain intact');

$row['is_read'] = 1;
$check($parse(NotificationService::encodeNotification($row, $now))[7] === '0',
    'read event is not new');

$row['notification_type'] = 'message';
try {
    NotificationService::encodeNotification($row, $now);
    throw new RuntimeException('Unverified notification type was accepted');
} catch (InvalidArgumentException) {
    // Types without verified native mapping must not emit broken wire data.
}

echo "MUCHOCORE_GD2209_NOTIFICATION_WIRE_OK\n";
