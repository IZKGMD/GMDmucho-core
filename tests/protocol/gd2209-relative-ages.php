<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

require_once dirname(__DIR__, 2) . '/src/Protocol/GdRelativeTime.php';
require_once dirname(__DIR__, 2) . '/src/Protocol/GdLegacyText.php';
require_once dirname(__DIR__, 2) . '/src/Protocol/ProtocolText.php';
require_once dirname(__DIR__, 2) . '/src/Protocol/GdCommentEncoder.php';

use MuchoCore\Protocol\GdRelativeTime;
use MuchoCore\Protocol\GdCommentEncoder;

$check = static function (bool $ok, string $label): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
    echo "PASS {$label}\n";
};

$now = 1700000000;
$cases = [
    [0, '0 seconds'],
    [1, '1 second'],
    [59, '59 seconds'],
    [60, '1 minute'],
    [125, '2 minutes'],
    [3600, '1 hour'],
    [7200, '2 hours'],
    [86400, '1 day'],
    [172800, '2 days'],
    [604800, '1 week'],
    [1209600, '2 weeks'],
    [2592000, '1 month'],
    [31536000, '1 year'],
];

foreach ($cases as [$offset, $expected]) {
    $actual = GdRelativeTime::fromEpoch($now - $offset, $now);
    $check($actual === $expected, "relative age {$offset}");
}
$check(GdRelativeTime::fromEpoch($now + 5, $now) === '0 seconds',
    'future timestamp clamps to zero');
$check(GdRelativeTime::fromEpoch(0, $now) === '0 seconds',
    'missing timestamp is safe');
$check(GdRelativeTime::fromDate('not a date', $now) === '0 seconds',
    'malformed DB date is safe');
$check(GdRelativeTime::fromDate(gmdate('Y-m-d H:i:s', $now - 125), $now) === '2 minutes',
    'database datetime converts to relative age');

$createdAt = gmdate('Y-m-d H:i:s', time() - 125);
$comment = [
    'id' => 71,
    'account_id' => 9,
    'content' => 'test',
    'likes' => -2,
    'is_spam' => 0,
    'percent' => 0,
    'created_at' => $createdAt,
];
$profile = [
    'account_id' => 9, 'user_id' => 10, 'username' => 'Tester',
    'cube' => 1, 'color1' => 0, 'color2' => 3,
];
$encoder = new GdCommentEncoder();
$wire = $encoder->encode($comment, $profile, 22, 51);
$account = $encoder->encodeAccountComment($comment, 22, 51);
$previous = $encoder->encode($comment, $profile, 22, 47);

$check(str_contains($wire, '~9~2 minutes~6~71'),
    'GD 2.209 level comment sends elapsed time without ago');
$check(str_contains($account, '~9~2 minutes~6~71'),
    'GD 2.209 account comment sends elapsed time without ago');
$check(str_contains($wire, '~15~' . strtotime($createdAt)),
    'GD 2.209 exact Unix timestamp is retained');
$check(!str_contains($previous, '~15~'),
    'older GD 2.2 does not receive new Unix timestamp');
$check(str_contains($previous, '~9~' . gmdate('d/m/Y G.i', strtotime($createdAt))),
    'older comment wire remains backward-compatible');

echo "MUCHOCORE_GD2209_RELATIVE_AGES_OK\n";
