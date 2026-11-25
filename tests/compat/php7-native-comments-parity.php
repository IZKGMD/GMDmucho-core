<?php
/** Compare native comment wire directly with the modern core encoder. */
declare(strict_types=1);
require __DIR__ . '/../../compat/php7/runtime.php';
require __DIR__ . '/../../compat/php7/interactions.php';
require __DIR__ . '/../../src/Protocol/ProtocolText.php';
require __DIR__ . '/../../src/Protocol/GdLegacyText.php';
require __DIR__ . '/../../src/Protocol/GdCommentEncoder.php';
require __DIR__ . '/../../compat/php7/core/src/Interaction/CommentService.php';

$compiledReflection = new ReflectionClass('MuchoCore\\Interaction\\CommentService');
$compiledService = $compiledReflection->newInstanceWithoutConstructor();
$normalize = $compiledReflection->getMethod('normalizeCommentText');
$normalize->setAccessible(true);
$shortcodes = ':fire: :skull:';
if ($normalize->invoke($compiledService, $shortcodes, 22) !== $shortcodes) {
    throw new RuntimeException('Compiled PHP 7 core stripped GD 2.209 emoji shortcodes');
}
if ($normalize->invoke($compiledService, 'legacy:comment|with~delimiters#', 19) !== 'legacycommentwithdelimiters') {
    throw new RuntimeException('Compiled PHP 7 core changed legacy delimiter sanitization');
}
$nativeWire = strtr(base64_encode($shortcodes), '+/', '-_');
if (mucho7_comment_text($nativeWire, 22) !== $shortcodes) {
    throw new RuntimeException('Native PHP 7 runtime failed to preserve GD 2.209 emoji shortcodes');
}

date_default_timezone_set('UTC');
$encoder = new MuchoCore\Protocol\GdCommentEncoder();
$withLegacyDisplayTime = static function (string $wire, int $binary): string {
    // PHP 8 sends an elapsed time in field 9 for GD 2.209. PHP 7 still uses the fixture date.
    if ($binary < 51) {
        return $wire;
    }
    $normalized = preg_replace(
        '/~9~\d+ (?:seconds?|minutes?|hours?|days?|weeks?|months?|years?)~/',
        '~9~22/10/2026 1.02~', $wire, 1, $count
    );
    if ($count !== 1 || $normalized === null) {
        throw new RuntimeException('Modern comment must carry one elapsed-time field');
    }
    return $normalized;
};
foreach (array(50,51) as $binary) {
    foreach (array('user','moderator','owner') as $role) {
        $row = array('id'=>7,'kind'=>1,'account_id'=>9,'user_id'=>42,'level_id'=>2,
            'content'=>'Native comment :fire: :skull: 😎','percent'=>85,'likes'=>2,'is_spam'=>0,
            'created_at'=>'2026-10-22 01:02:03','username'=>'Native','icon_id'=>5,
            'color1'=>2,'color2'=>3,'icon_type'=>0,'glow'=>1,'role'=>$role);
        $profile = array_merge($row,array('cube'=>5,'special'=>1,
            'badge'=>$role==='owner' ? 2 : ($role==='moderator' ? 1 : 0)));
        if (mucho7_comment_wire($row,22,$binary) !==
            $withLegacyDisplayTime($encoder->encode($row,$profile,22,$binary), $binary)) {
            throw new RuntimeException('Native level comment wire differs from modern encoder');
        }
        $row['kind'] = 2;
        if (mucho7_comment_wire($row,22,$binary) !==
            $withLegacyDisplayTime($encoder->encodeAccountComment($row,22,$binary), $binary)) {
            throw new RuntimeException('Native profile post wire differs from modern encoder');
        }
    }
}
echo "PHP7_NATIVE_COMMENTS_WIRE_PARITY_OK\n";
