<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Protocol/GdLegacyText.php';
require dirname(__DIR__, 2) . '/src/Protocol/GdCommentEncoder.php';

use MuchoCore\Protocol\GdLegacyText;
use MuchoCore\Protocol\GdCommentEncoder;

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('GD comment Base64 wire: ' . $message);
    }
};

$text = 'Привет! #152 ~ : | тест ✓';
$wire = strtr(base64_encode($text), '+/', '-_');
$assert(
    GdLegacyText::decodeComment($wire, 22) === $text,
    'GD 2.209 upload is decoded for plain DB storage'
);
$assert(
    GdLegacyText::decodeComment($wire, 21) === $text,
    'GD 2.1 upload is decoded'
);
$assert(
    GdLegacyText::decodeComment('legacy text', 19) === 'legacy text',
    'GD 1.9 plain upload was changed'
);
$assert(
    GdLegacyText::encodeCommentForResponse($text, 22) === $wire,
    'GD 2.209 comments must be encoded for response key 2'
);
$assert(
    GdLegacyText::encodeCommentForResponse($text, 21) === $wire,
    'GD 2.1 comments must be encoded'
);
$assert(
    GdLegacyText::encodeCommentForResponse($wire, 22) === $wire,
    'migrated Base64 comment was double-encoded'
);
$assert(
    GdLegacyText::encodeCommentForResponse('legacy text', 19) === 'legacy text',
    'GD 1.9 plain response was changed'
);

$comment = [
    'id' => 17,
    'account_id' => 9,
    'content' => $text,
    'likes' => 1,
    'is_spam' => 0,
    'percent' => 0,
    'created_at' => '2026-10-21 00:00:00',
];
$profile = [
    'account_id' => 9,
    'user_id' => 8,
    'username' => 'Tester',
    'cube' => 1,
    'color1' => 0,
    'color2' => 3,
];
$encoder = new GdCommentEncoder();
$levelWire = $encoder->encode($comment, $profile, 22, 51);
$accountWire = $encoder->encodeAccountComment($comment, 22, 51);
$assert(str_starts_with($levelWire, '2~' . $wire . '~3~'),
    'level comment key 2 is not Base64');
$assert(str_starts_with($accountWire, '2~' . $wire . '~3~'),
    'account comment key 2 is not Base64');
$assert(str_contains($levelWire, '~15~1792540800'),
    '2.209 epoch field removed');

echo "MUCHOCORE_GD_2209_COMMENT_BASE64_OK\n";
