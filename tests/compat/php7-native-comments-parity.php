<?php
/** Compare native comment wire directly with the modern core encoder. */
declare(strict_types=1);
require __DIR__ . '/../../compat/php7/runtime.php';
require __DIR__ . '/../../compat/php7/interactions.php';
require __DIR__ . '/../../src/Protocol/ProtocolText.php';
require __DIR__ . '/../../src/Protocol/GdLegacyText.php';
require __DIR__ . '/../../src/Protocol/GdCommentEncoder.php';
date_default_timezone_set('UTC');
$encoder = new MuchoCore\Protocol\GdCommentEncoder();
foreach (array(50,51) as $binary) {
    foreach (array('user','moderator','owner') as $role) {
        $row = array('id'=>7,'kind'=>1,'account_id'=>9,'user_id'=>42,'level_id'=>2,
            'content'=>'Native comment 😎','percent'=>85,'likes'=>2,'is_spam'=>0,
            'created_at'=>'2026-10-22 01:02:03','username'=>'Native','icon_id'=>5,
            'color1'=>2,'color2'=>3,'icon_type'=>0,'glow'=>1,'role'=>$role);
        $profile = array_merge($row,array('cube'=>5,'special'=>1,
            'badge'=>$role==='owner' ? 2 : ($role==='moderator' ? 1 : 0)));
        if (mucho7_comment_wire($row,22,$binary) !== $encoder->encode($row,$profile,22,$binary)) {
            throw new RuntimeException('Native level comment wire differs from modern encoder');
        }
        $row['kind'] = 2;
        if (mucho7_comment_wire($row,22,$binary) !== $encoder->encodeAccountComment($row,22,$binary)) {
            throw new RuntimeException('Native profile post wire differs from modern encoder');
        }
    }
}
echo "PHP7_NATIVE_COMMENTS_WIRE_PARITY_OK\n";
