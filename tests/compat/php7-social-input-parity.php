<?php
/**
 * Shared PHP8 + compiled PHP7 guard for GD 2.209 social endpoints.
 * This tests security-sensitive source invariants and the real PHP7 Request
 * parser. Full MariaDB vote and message integration runs separately in CI.
 */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require_once $root . '/compat/php7/domain.php';

function socialGuard($ok, $why) {
    if (!$ok) {
        throw new RuntimeException('GD 2.209 social hardening: ' . $why);
    }
}
foreach (array('src', 'compat/php7/core/src') as $source) {
    $like = file_get_contents($root . '/' . $source . '/Interaction/LikeController.php');
    $comment = file_get_contents($root . '/' . $source . '/Interaction/CommentController.php');
    $message = file_get_contents($root . '/' . $source . '/Social/MessageRepository.php');
    $relationships = file_get_contents($root . '/' . $source . '/Social/RelationshipRepository.php');
    socialGuard(is_string($like) && is_string($comment) && is_string($message),
        'missing source in ' . $source);
    foreach (array(
        "array_key_exists('accountID', \$r->post)",
        "array_key_exists('itemID', \$r->post)",
        "array_key_exists('type', \$r->post)",
        "array_key_exists('like', \$r->post)",
        'if ($accountId < 0)',
        "return Response::text(\$success ? '1' : '-1')",
    ) as $needle) {
        socialGuard(strpos($like, $needle) !== false,
            'like controller missing guard in ' . $source . ': ' . $needle);
    }
    socialGuard(
        strpos($comment, '(int) ($_POST') === false &&
        strpos($comment, '(int)($_POST') === false &&
        strpos($comment, '?: $_POST[') === false &&
        strpos($comment, '?: ($_POST') === false &&
        strpos($comment, 'return $request->postString($key);') !== false,
        'comment controller uses unsafe global fallback in ' . $source);
    socialGuard(is_string($relationships) &&
        substr_count($relationships, 'LEFT JOIN profiles p') >= 6,
        'friend and request joins incorrectly drop legacy missing profiles in ' . $source);
    socialGuard(
        strpos($message, 'AND to_account_id=:recipient') !== false &&
        strpos($message, 'AND is_receiver_deleted=0') !== false &&
        strpos($message, 'AND is_read=0') !== false,
        'private message read-state updates are not recipient-scoped in ' . $source);
}
$bad = new \MuchoCore\Http\Request('POST', '/database/likeGJItem211.php',
    array(), array('accountID'=>'2junk','itemID'=>'1junk','type'=>'x'), array());
foreach (array('accountID','itemID','type') as $field) {
    socialGuard($bad->postInt($field, -1) === -1,
        'Request accepted malformed field ' . $field);
}
echo "MUCHOCORE_PHP7_SOCIAL_INPUT_PARITY_OK\n";
