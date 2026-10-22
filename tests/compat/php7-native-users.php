<?php
/** Offline native PHP7 public profile/search/score safety and wire shape. */
declare(strict_types=1);
require __DIR__ . '/../../compat/php7/runtime.php';
require __DIR__ . '/../../compat/php7/users.php';

function check7users($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException('PHP7 users: ' . $message);
    }
}
$user = array(
    'account_id'=>42, 'user_id'=>70, 'username'=>'NativeTester',
    'stars'=>120, 'moons'=>5, 'diamonds'=>17,
    'secret_coins'=>6, 'user_coins'=>4, 'demons'=>2,
    'creator_points'=>8, 'icon_id'=>19, 'icon_type'=>1,
    'color1'=>2, 'color2'=>5, 'glow'=>1
);
$profile = mucho7_user_profile_wire($user);
check7users(strpos($profile,'1:NativeTester:2:70:13:6:17:4')===0,
    'canonical public profile field ordering');
check7users(strpos($profile,':16:42:31:0')!==false,
    'public account identity present');
check7users(substr($profile,-4)==='29:1',
    'canonical trailing profile field');
check7users(strpos($profile,'password')===false &&
    strpos($profile,'email')===false,
    'no sensitive fields present');
$list = mucho7_user_list_entry($user, false);
check7users(strpos($list,'1:NativeTester:2:70:13:6:17:4:9:19')===0,
    'public search entry keys');
$leader = mucho7_user_list_entry($user, true);
check7users(strpos($leader,':6:0:9:19')!==false &&
    strpos($leader,':7:42:46:17:52:5')!==false,
    'public leaderboard fields');
check7users(mucho7_dispatch(null,'/database/getGJUserInfo20.php','POST',
    array('targetAccountID'=>'42'))==='-1',
    'no database cannot leak profile');
check7users(mucho7_dispatch(null,'/database/getGJUsers20.php','GET',
    array('str'=>'Native'))==='-1',
    'user search is POST only');
check7users(mucho7_dispatch(null,'/database/getGJScores20.php','POST',
    array('type'=>'friends'))==='-1',
    'no DB or account context cannot leak friend leaderboard');
echo "PHP7_NATIVE_PUBLIC_USERS_PURE_OK\n";
