<?php
/** Disposable MariaDB contract for GD 2.209 native PHP7 friends. */
declare(strict_types=1);
$root = dirname(__DIR__,2);
foreach (array('runtime','auth','schema','social') as $module) {
    require_once $root . '/compat/php7/' . $module . '.php';
}
function socialAssert($yes,$message) {
    if (!$yes) { throw new RuntimeException($message); }
}
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
$password = getenv('TEST_DB_ROOT_PASSWORD');
if ($password === false) { throw new RuntimeException('TEST_DB_ROOT_PASSWORD required'); }
$dsn = 'mysql:host='.$host.';port='.$port.';charset=utf8mb4';
$opt = array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC);
$admin = new PDO($dsn,'root',$password,$opt);
$database = 'mucho_php7_social_ci';
$admin->exec('DROP DATABASE IF EXISTS mucho_php7_social_ci');
$admin->exec('CREATE DATABASE mucho_php7_social_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $pdo = new PDO($dsn.';dbname='.$database,'root',$password,$opt);
    mucho7_create_schema($pdo);
    mucho7_upgrade_social($pdo);
    mucho7_upgrade_social($pdo); // additive/idempotent
    $plain = 'NativeSocialPass_123';
    $hash = password_hash($plain,PASSWORD_BCRYPT,array('cost'=>4));
    $gjp = sha1($plain . 'mI29fmAnxgTs');
    $gjpHash = password_hash($gjp,PASSWORD_BCRYPT,array('cost'=>4));
    $insert = $pdo->prepare('INSERT INTO accounts(account_id,username,email,password_hash,gjp2_hash)'
        . ' VALUES(?,?,?,?,?)');
    $insert->execute(array(1,'Alpha','a@example.invalid',$hash,$gjpHash));
    $insert->execute(array(2,'Beta','b@example.invalid',$hash,$gjpHash));
    $insert->execute(array(3,'Gamma','c@example.invalid',$hash,$gjpHash));
    $pdo->exec('INSERT INTO profiles(user_id,account_id) VALUES(12,1),(34,2),(56,3)');
    $opts = array('enable_accounts'=>true,'enable_registration'=>false,
        'enable_writes'=>true,'enable_social'=>true);
    $alpha = array('accountID'=>'1','gjp2'=>$gjp,'gameVersion'=>'22','binaryVersion'=>'51');
    $beta = array('accountID'=>'2','gjp2'=>$gjp,'gameVersion'=>'22','binaryVersion'=>'51');
    $gamma = array('accountID'=>'3','gjp2'=>$gjp,'gameVersion'=>'22','binaryVersion'=>'51');
    $call = function($route,$data,$features=null) use($pdo,$opts) {
        return mucho7_dispatch($pdo,'/a/accounts/'.$route.'.php','POST',$data,
            $features === null ? $opts : $features);
    };
    socialAssert($call('getGJFriendRequests20',$beta)==='-2','empty inbox');
    $comment = strtr(base64_encode('Привет 😎'),'+/','-_');
    $send = array_merge($alpha,array('toAccountID'=>'2','comment'=>$comment));
    socialAssert($call('uploadFriendRequest20',$send,array('enable_accounts'=>true))==='-1',
        'opt-in defaults off');
    socialAssert($call('uploadFriendRequest20',$send)==='1','new friend request');
    socialAssert($call('uploadFriendRequest20',$send)==='-1','duplicate denied');
    socialAssert($call('uploadFriendRequest20',array_merge($beta,
        array('toAccountID'=>'1','comment'=>'')))==='-1','reverse duplicate denied');
    socialAssert($call('uploadFriendRequest20',array_merge($alpha,
        array('toAccountID'=>'1','comment'=>'')))==='-1','self request denied');
    socialAssert($call('uploadFriendRequest20',array_merge($alpha,
        array('toAccountID'=>'3','comment'=>'%%%')))==='-1','invalid comment denied');
    socialAssert($call('getGJFriendRequests20',array_merge($gamma,
        array('accountID'=>'2')))==='-1','other authenticated inbox is not visible');
    socialAssert($call('getGJFriendRequests20',array_merge($beta,
        array('gjp2'=>'wrong')))==='-1','forged credential rejected');
    $inbox = $call('getGJFriendRequests20',array_merge($beta,array('page'=>'0')));
    socialAssert(strpos($inbox,'1:Alpha:2:12:9:1:')===0,
        'inbox contains real GD userID and authenticated requester');
    socialAssert(strpos($inbox,':35:'.$comment.':41:1:37:')!==false,
        'UTF8 comment roundtrip and unread request');
    socialAssert(substr($inbox,-7)==='#1:0:10','inbox pagination');
    $sent = $call('getGJFriendRequests22',array_merge($alpha,array('getSent'=>'1')));
    socialAssert(strpos($sent,'1:Beta:2:34:')===0,'outbox pagination and target identity');
    $check = $pdo->query('SELECT id FROM php7_friend_requests LIMIT 1')->fetchColumn();
    $rid = (int)$check;
    socialAssert($rid>0,'request stored');
    socialAssert($call('readGJFriendRequest20',array_merge($gamma,
        array('requestID'=>(string)$rid)))==='-1','stranger cannot read request');
    socialAssert($call('readGJFriendRequest20',array_merge($beta,
        array('requestID'=>(string)$rid)))==='1','recipient marks as seen');
    socialAssert(strpos($call('getGJFriendRequests20',$beta),':41:0:37:')!==false,
        'read request unread state cleared');
    socialAssert($call('acceptGJFriendRequest20',array_merge($gamma,
        array('requestID'=>(string)$rid)))==='-1','cannot accept other inbox');
    socialAssert($call('acceptGJFriendRequest20',array_merge($beta,
        array('requestID'=>(string)$rid)),array('enable_accounts'=>true,
        'enable_social'=>true))==='-1','writes independently gated');
    socialAssert($call('acceptGJFriendRequest20',array_merge($beta,
        array('requestID'=>(string)$rid)))==='1','recipient can accept');
    socialAssert($call('acceptGJFriendRequest20',array_merge($beta,
        array('requestID'=>(string)$rid)))==='-1','consumed request cannot be replayed');
    socialAssert($call('getGJFriendRequests20',$beta)==='-2','accepted removed from inbox');
    $friendsA = $call('getGJUserList20',array_merge($alpha,array('type'=>'0')));
    $friendsB = $call('getGJUserList20',array_merge($beta,array('type'=>'0')));
    socialAssert(strpos($friendsA,'1:Beta:2:34:')===0,'friend list for sender');
    socialAssert(strpos($friendsB,'1:Alpha:2:12:')===0,'friend list for recipient');
    socialAssert($call('getGJUserList20',array_merge($alpha,
        array('type'=>'1')))==='-2','blocked users not leaked as friends');
    socialAssert($call('removeGJFriend20',array_merge($gamma,
        array('targetAccountID'=>'2')))==='-1','unrelated user cannot remove');
    socialAssert($call('removeGJFriend20',array_merge($alpha,
        array('targetAccountID'=>'2')))==='1','friend can remove');
    socialAssert($call('getGJUserList20',array_merge($alpha,
        array('type'=>'0')))==='-2','friend removed both directions');
    // Independent ignored and cancelled request paths.
    socialAssert($call('uploadFriendRequest20',$send)==='1','can re-request after removal');
    socialAssert($call('deleteGJFriendRequests20',array_merge($beta,
        array('targetAccountID'=>'1','isSender'=>'0')))==='1','recipient ignores request');
    socialAssert($call('getGJFriendRequests20',$beta)==='-2','ignored hidden from inbox');
    socialAssert(strpos($call('getGJFriendRequests20',array_merge($beta,
        array('getSent'=>'2'))),'1:Alpha:')===0,'ignored requests query');
    socialAssert($call('deleteGJFriendRequests20',array_merge($alpha,
        array('targetAccountID'=>'2','isSender'=>'1')))==='1','sender cancels request');
    socialAssert($call('getGJFriendRequests20',array_merge($beta,
        array('getSent'=>'2')))==='-2','cancel removes ignored request');
    echo "PHP7_NATIVE_SOCIAL_GD2209_OK\n";
} finally {
    $admin->exec('DROP DATABASE IF EXISTS mucho_php7_social_ci');
}
