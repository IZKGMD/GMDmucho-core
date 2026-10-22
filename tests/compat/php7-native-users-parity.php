<?php
/** Compare PHP7 public user fields byte-for-byte to the modern encoder. */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/compat/php7/runtime.php';
require dirname(__DIR__, 2) . '/compat/php7/users.php';
require dirname(__DIR__, 2) . '/src/Protocol/ProtocolText.php';
require dirname(__DIR__, 2) . '/src/User/GameRole.php';
require dirname(__DIR__, 2) . '/src/Protocol/GdUserEncoder.php';

use MuchoCore\Protocol\GdUserEncoder;

$row = [
    'account_id'=>42, 'user_id'=>70, 'username'=>'NativeTester',
    'stars'=>120, 'moons'=>5, 'diamonds'=>17,
    'secret_coins'=>6, 'user_coins'=>4, 'demons'=>2,
    'creator_points'=>8, 'icon_id'=>19, 'icon_type'=>1,
    'color1'=>2, 'color2'=>5, 'glow'=>1, 'role_code'=>'user'
];
$modern = new GdUserEncoder();
$cases = [
    ['public profile', $modern->profile($row,22,51),
        mucho7_user_profile_wire($row)],
    ['public search entry', explode('#', $modern->search([$row],1,0,10))[0],
        mucho7_user_list_entry($row,false)],
    ['public leaderboard entry', $modern->leaderboard([$row]),
        mucho7_user_list_entry($row,true)]
];
foreach ($cases as $case) {
    if ($case[1] !== $case[2]) {
        throw new RuntimeException('PHP7 public user wire differs: ' . $case[0] .
            "\nModern: " . $case[1] . "\nNative: " . $case[2]);
    }
}
echo "PHP7_NATIVE_PUBLIC_USERS_WIRE_PARITY_OK\n";
