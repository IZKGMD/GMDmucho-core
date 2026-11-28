<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Protocol\GdCommentEncoder;

$e = new GdCommentEncoder();
$comment = [
    'id' => 71, 'content' => 'test', 'created_at' => '2026-10-17 00:00:00',
    'likes' => 3, 'is_spam' => 0, 'percent' => 12, 'account_id' => 9,
];
$profile = [
    'account_id' => 9, 'user_id' => 10, 'username' => 'Tester',
    'cube' => 1, 'color1' => 0, 'color2' => 3,
];
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('GD 2.209 comment wire: ' . $message);
    }
};

// Full level comment wire has user segment after ':'; parse comment keys
// without mistaking legacy user field 15 for the new comment field.
$legacy = explode(':', $e->encode($comment, $profile, 22, 47), 2)[0];
$new = explode(':', $e->encode($comment, $profile, 22, 51), 2)[0];
$assert(!str_contains($legacy, '~15~'), '2.2081 must keep its legacy wire');
$assert(str_contains($new, '~15~1792195200'), 'exact UTC Unix timestamp absent');
$assert(str_contains($new, '~6~71'), 'comment ID changed');
$assert(str_contains($legacy, '~9~17/10/2026'), 'legacy date field 9 changed');
$assert(preg_match('/~9~[0-9]+ (?:second|minute|hour|day|week|month|year)s?~/', $new) === 1,
    'field 9 must supply an elapsed time for the native client');

// Account comment endpoint must gate timestamp on same version.
$oldAcc = $e->encodeAccountComment($comment, 22, 47);
$newAcc = $e->encodeAccountComment($comment, 22, 51);
$assert(!str_contains($oldAcc, '~15~'), 'older account comment changed');
$assert(str_contains($newAcc, '~15~1792195200'), 'account comment exact time absent');
$recent = $e->encodeAccountComment($comment + ['created_epoch' => time() - 600], 22, 51);
$assert(str_contains($recent, '~9~10 minutes~'), 'account comment must use the database epoch');
$assert(!str_contains($e->encodeAccountComment($comment, 21, 51), '~15~'),
    'GD 2.1 should remain unchanged');

echo "MUCHOCORE_GD_2209_COMMENT_TIMESTAMPS_OK\n";
