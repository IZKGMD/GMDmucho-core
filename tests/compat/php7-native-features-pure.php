<?php
/** Runs on PHP 7.0 without MySQL or any modern core classes. */
declare(strict_types=1);
require __DIR__ . '/../../compat/php7/runtime.php';
require __DIR__ . '/../../compat/php7/auth.php';
require __DIR__ . '/../../compat/php7/interactions.php';

function featureCheck($ok, $message)
{
    if (!$ok) { throw new RuntimeException($message); }
}
featureCheck(mucho7_mount_uri('/mc/a/getGJComments21.php', '/mc/public/php7.php')
    === '/a/getGJComments21.php', 'nested shared mount');
featureCheck(mucho7_mount_uri('/a/getGJComments21.php', '/mc/public/php7.php')
    === '/a/getGJComments21.php', 'root alias must survive mount stripping');
featureCheck(mucho7_mount_uri('/mc/public/health', '/mc/public/php7.php')
    === '/health', 'public directory mount');
featureCheck(mucho7_mount_uri('/mc-other/health', '/mc/public/php7.php')
    === '/mc-other/health', 'mount boundary');
featureCheck(mucho7_credential(array('gjp2'=>'','gjp'=>'legacy')) === 'legacy',
    'empty modern credential must not suppress legacy credential');
featureCheck(mucho7_comment_text(strtr(base64_encode('Привет 😎'), '+/', '-_'),22)
    === 'Привет 😎', 'UTF-8 comment decoding');
featureCheck(mucho7_comment_text('%%%',22) === false, 'invalid comment base64');
featureCheck(mucho7_comment_text(base64_encode("a\0b"),22) === false, 'control bytes');
featureCheck(mucho7_comment_text(base64_encode(str_repeat('я',301)),22) === false,
    'character limit independent of UTF-8 byte length');
$row = array('id'=>7,'kind'=>1,'account_id'=>9,'user_id'=>42,'level_id'=>2,
    'content'=>'Привет 😎','percent'=>85,'likes'=>2,'is_spam'=>0,
    'created_at'=>'2026-10-22 01:02:03','username'=>'Native','icon_id'=>5,
    'color1'=>2,'color2'=>3,'icon_type'=>0,'glow'=>1,'role'=>'user');
$wire = mucho7_comment_wire($row,22,51);
featureCheck(strpos($wire,'~15~'.mucho7_epoch($row['created_at'])) !== false,
    '2.209 exact comment timestamp');
featureCheck(strpos($wire,':1~Native~7~1~9~5') !== false, 'embedded comment author');
featureCheck(strpos(mucho7_comment_wire($row,22,50),'~15~'.mucho7_epoch($row['created_at'])) === false,
    'pre-2.209 must omit exact comment timestamp');
featureCheck(strpos(mucho7_comment_wire($row,22,51,true),'1~2~2~') === 0,
    'history level id prefix');
echo "PHP7_NATIVE_FEATURES_PURE_OK\n";
