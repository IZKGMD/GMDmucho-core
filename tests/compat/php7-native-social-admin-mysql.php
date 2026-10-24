<?php
/** Real database privacy, native administration and upgrade regression. PHP 7.0. */
declare(strict_types=1);
$root=dirname(__DIR__,2);
foreach(array('bootstrap','runtime','schema','auth','download','upload','users','interactions','cloudsave','profile','social','levels','news','admin') as $module){require_once $root.'/compat/php7/'.$module.'.php';}
function sharedCheck($ok,$message){if(getenv("SHARED_TEST_TRACE")){fwrite(STDERR,$message."\n");}if(!$ok){throw new RuntimeException($message);}}
$host=getenv('TEST_DB_HOST')?:'127.0.0.1';$port=(int)(getenv('TEST_DB_PORT')?:3306);
$password=getenv('TEST_DB_ROOT_PASSWORD');if($password===false){throw new RuntimeException('TEST_DB_ROOT_PASSWORD required');}
$dsn='mysql:host='.$host.';port='.$port.';charset=utf8mb4';
$attrs=array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC);
$admin=new PDO($dsn,'root',$password,$attrs);$db='mucho_php7_social_ci';
$admin->exec('DROP DATABASE IF EXISTS mucho_php7_social_ci');$admin->exec('CREATE DATABASE mucho_php7_social_ci CHARACTER SET utf8mb4');
$stage=sys_get_temp_dir().'/mucho7-admin-'.bin2hex(random_bytes(8));mkdir($stage.'/config',0700,true);mkdir($stage.'/public',0700,true);
try {
    $pdo=new PDO($dsn.';dbname='.$db,'root',$password,$attrs);
    $settings=array('host'=>$host,'port'=>$port,'database'=>$db,'username'=>'root','password'=>'PrivateDbPass_123',
        'site_url'=>'https://example.invalid','enable_accounts'=>false,'enable_writes'=>false);
    file_put_contents($stage.'/config/php7-local.php','<?php return '.var_export($settings,true).';');
    $bootstrap=array('db_password'=>'wrong','userName'=>'Owner','password'=>'NativePass123!',
        'email'=>'owner@example.invalid','site_url'=>'https://example.invalid','enable_features'=>'1');
    try {mucho7_admin_initialize($pdo,$settings,$bootstrap,$stage);throw new RuntimeException('bad bootstrap accepted');}
    catch(RuntimeException $e){sharedCheck($e->getMessage()==='Incorrect database password.','bootstrap requires existing DB secret');}
    sharedCheck(count($pdo->query('SHOW TABLES')->fetchAll())===0,'bad bootstrap must not touch schema');
    $bootstrap['db_password']=$settings['password'];$owner=mucho7_admin_initialize($pdo,$settings,$bootstrap,$stage);
    sharedCheck($owner===1 && mucho7_admin_has_owner($pdo),'first owner setup');
    $saved=require $stage.'/config/php7-local.php';
    sharedCheck(!empty($saved['enable_accounts']) && !empty($saved['enable_social']) && strlen($saved['auth_pepper'])===64,'setup enables selected functions and generates secrets');
    sharedCheck(is_file($stage.'/public/php7-mode.on'),'setup activates native routes');
    try {mucho7_admin_bootstrap($pdo,$settings,$bootstrap);throw new RuntimeException('second owner bootstrap accepted');}
    catch(RuntimeException $e){sharedCheck(strpos($e->getMessage(),'owner already exists')!==false,'bootstrap closes after owner exists');}
    $gjp=sha1('NativePass123!mI29fmAnxgTs');$hash=password_hash('NativePass123!',PASSWORD_BCRYPT,array('cost'=>4));$gjpHash=password_hash($gjp,PASSWORD_BCRYPT,array('cost'=>4));
    $insert=$pdo->prepare('INSERT INTO accounts(account_id,username,email,password_hash,gjp2_hash,role_id) VALUES(?,?,?,?,?,?)');
    foreach(array(2=>'Peer',3=>'Outsider',4=>'Mod') as $id=>$name){
        $insert->execute(array($id,$name,strtolower($name).'@example.invalid',$hash,$gjpHash,$id===4?2:1));
        $pdo->prepare('INSERT INTO profiles(user_id,account_id) VALUES(?,?)')->execute(array($id*10,$id));
    }
    $options=array('enable_accounts'=>true,'enable_writes'=>true,'enable_cloudsave'=>true,'enable_social'=>true,
        'auth_pepper'=>str_repeat('p',64),'ip'=>'127.0.0.1');
    $call=function($who,$route,$extra=array(),$opts=null)use($pdo,$gjp,$options){
        return mucho7_dispatch($pdo,'/a/'.$route.'.php','POST',array_merge(array('accountID'=>(string)$who,'gjp2'=>$gjp,'gameVersion'=>'22','binaryVersion'=>'51'),$extra),$opts===null?$options:$opts);
    };
    sharedCheck($call(1,'uploadFriendRequest20',array('toAccountID'=>'2','comment'=>base64_encode('Hello')))==='1','send friend request');
    sharedCheck($call(2,'uploadFriendRequest20',array('toAccountID'=>'1'))==='-1','reverse pending request rejected');
    sharedCheck(strpos($call(2,'getGJFriendRequests20'),'1:Owner:2:1')===0,'incoming request identity');
    sharedCheck(strpos($call(1,'getGJFriendRequests20',array('getSent'=>'1')),'1:Peer:2:20')===0,'sent request peer identity');
    sharedCheck(strpos($call(2,'getGJUserInfo20',array('targetAccountID'=>'1')),':31:3:')!==false,'incoming profile friend state');
    sharedCheck(strpos($call(1,'getGJUserInfo20',array('targetAccountID'=>'2')),':31:4:')!==false,'outgoing profile friend state');
    sharedCheck($call(3,'acceptGJFriendRequest20',array('requestID'=>'1'))==='-1','stranger cannot accept request');
    sharedCheck($call(2,'acceptGJFriendRequest20',array('requestID'=>'1'))==='1','recipient accepts request');
    sharedCheck((int)$pdo->query('SELECT COUNT(*) FROM php7_friends')->fetchColumn()===2,'friendship symmetric');
    sharedCheck(strpos($call(1,'getGJUserList20',array('type'=>'0')),'1:Peer:2:20')===0,'friend list');
    sharedCheck($call(2,'updateGJAccSettings20',array('mS'=>'1','cS'=>'1','frS'=>'1'))==='1','privacy settings');
    sharedCheck($call(3,'uploadFriendRequest20',array('toAccountID'=>'2'))==='-1','closed friend requests');
    $message=array('toAccountID'=>'2','subject'=>base64_encode('Subject'),'body'=>strtr(base64_encode('Opaque XOR body'),'+/','-_'));
    sharedCheck($call(3,'uploadGJMessage20',$message)==='-1','friends-only message privacy');
    sharedCheck($call(1,'uploadGJMessage20',$message)==='1','friend sends message');
    sharedCheck(strpos($call(2,'getGJMessages20'),'6:Owner:3:1:2:1:1:1:')===0,'message inbox wire');
    sharedCheck($call(3,'downloadGJMessage20',array('messageID'=>'1'))==='-1','stranger cannot read private message');
    sharedCheck(strpos($call(2,'downloadGJMessage20',array('messageID'=>'1')),':8:1:9:0:5:'.$message['body'])!==false,'recipient reads and marks message');
    sharedCheck($call(1,'deleteGJMessages20',array('messageID'=>'1','isSender'=>'0'))==='-1','sender cannot delete receiver copy');
    sharedCheck($call(1,'deleteGJMessages20',array('messageID'=>'1','isSender'=>'1'))==='1','sender deletes own copy');
    sharedCheck($call(1,'downloadGJMessage20',array('messageID'=>'1'))==='-1','deleted message remains inaccessible to sender');
    sharedCheck($call(2,'downloadGJMessage20',array('messageID'=>'1'))!=='-1','receiver copy survives sender deletion');
    sharedCheck($call(2,'deleteGJMessages20',array('messageID'=>'1'))==='1','receiver deletes own copy');
    sharedCheck($call(2,'downloadGJMessage20',array('messageID'=>'1'))==='-1','receiver deletion enforced on direct read');
    sharedCheck($call(2,'updateGJUserScore22',array('accShip'=>'17','accBall'=>'8','color3'=>'-1','special'=>'1','stars'=>'25'))==='20','extended appearance score');
    $wire=$call(1,'getGJUserInfo20',array('targetAccountID'=>'2'));
    sharedCheck(strpos($wire,':51:-1:')!==false && strpos($wire,':22:17:23:8:')!==false,'extended appearance roundtrip');
    sharedCheck(strpos($call(1,'getGJUserInfo20',array('targetAccountID'=>'1')),':49:2:')!==false,'owner badge from real role');
    $pdo->exec("INSERT INTO levels(account_id,name,level_data) VALUES(2,'Private','kS1,1;')");
    sharedCheck($call(2,'uploadGJComment21',array('levelID'=>'1','comment'=>base64_encode('Private history')))==='1','history fixture');
    sharedCheck(mucho7_dispatch($pdo,'/getGJCommentHistory.php','POST',array('userID'=>'20'),$options)==='-1','anonymous cannot read private history');
    sharedCheck($call(3,'getGJCommentHistory',array('userID'=>'20'))==='-1','stranger cannot read friends-only history');
    sharedCheck($call(1,'getGJCommentHistory',array('userID'=>'20'))!=='-1','friend can read allowed history');
    sharedCheck($call(2,'uploadGJAccComment20',array('comment'=>base64_encode('Profile post')))==='-1','account comment alias reaches cooldown');
    $pdo->exec('DELETE FROM php7_comments');
    sharedCheck($call(2,'uploadGJAccComment20',array('comment'=>base64_encode('Profile post')))==='1','original account-comment upload alias');
    $commentId=(string)$pdo->query('SELECT id FROM php7_comments')->fetchColumn();
    sharedCheck($call(2,'deleteGJAccComment20',array('commentID'=>$commentId))==='1','original account-comment delete alias');
    $pdo->exec('UPDATE levels SET unlisted2=1 WHERE level_id=1');
    sharedCheck($call(3,'downloadGJLevel22',array('levelID'=>'1'))==='-1','stranger cannot download friend-only level');
    sharedCheck($call(1,'downloadGJLevel22',array('levelID'=>'1'))!=='-1','friend can download friend-only level');
    sharedCheck($call(2,'downloadGJLevel22',array('levelID'=>'1'))!=='-1','owner can download friend-only level');
    sharedCheck($call(1,'blockGJUser20',array('targetAccountID'=>'2'))==='1','block peer');
    sharedCheck((int)$pdo->query('SELECT COUNT(*) FROM php7_friends')->fetchColumn()===0,'block removes both friendship directions');
    sharedCheck($call(1,'uploadGJMessage20',$message)==='-1','block stops outgoing messages too');
    sharedCheck($call(2,'uploadGJMessage20',array_merge($message,array('toAccountID'=>'1')))==='-1','block stops incoming messages');
    sharedCheck($call(1,'downloadGJLevel22',array('levelID'=>'1'))==='-1','block revokes private-level friend access');
    sharedCheck($call(1,'getGJCommentHistory',array('userID'=>'20'))==='-1','block revokes history friend access');
    sharedCheck($call(1,'unblockGJUser20',array('targetAccountID'=>'2'))==='1','unblock');
    sharedCheck(!mucho7_social_friend($pdo,1,2),'unblock does not restore old friendship');
    sharedCheck($call(1,'getGJMessages20',array(),array('enable_accounts'=>true))==='-1','social module opt-in');
    sharedCheck($call(1,'getGJMessages20',array('gjp2'=>'wrong'))==='-1','private reads require real credential');
    try{mucho7_admin_action($pdo,2,'player',array('id'=>'3','role'=>'owner'),$stage,$settings);throw new RuntimeException('user admin accepted');}
    catch(RuntimeException $e){sharedCheck($e->getMessage()==='No admin access.','ordinary account denied admin actions');}
    try{mucho7_admin_action($pdo,4,'rate_level',array('id'=>'1'),$stage,$settings);throw new RuntimeException('moderator rating accepted');}
    catch(RuntimeException $e){sharedCheck($e->getMessage()==='No permission for this action.','regular moderator cannot directly rate');}
    mucho7_admin_action($pdo,1,'rate_level',array('id'=>'1','stars'=>'5','difficulty'=>'30','featured'=>'1','epic'=>'2'),$stage,$settings);
    sharedCheck((int)$pdo->query('SELECT creator_points FROM profiles WHERE account_id=2')->fetchColumn()===4,'rating recalculates creator points');
    try{mucho7_admin_action($pdo,1,'player',array('id'=>'1','role'=>'user'),$stage,$settings);throw new RuntimeException('self demotion accepted');}
    catch(RuntimeException $e){sharedCheck(strpos($e->getMessage(),'your own')!==false,'self owner lockout prevented');}
    mucho7_admin_action($pdo,1,'delete_level',array('id'=>'1'),$stage,$settings);
    sharedCheck((int)$pdo->query('SELECT is_deleted FROM levels')->fetchColumn()===1,'admin soft delete');
    mucho7_admin_action($pdo,1,'restore_level',array('id'=>'1'),$stage,$settings);
    sharedCheck((int)$pdo->query('SELECT creator_points FROM profiles WHERE account_id=2')->fetchColumn()===4,'restore preserves rating and creator points');
    mucho7_admin_action($pdo,1,'save_news',array('title'=>'Shared news','body'=>'Native admin works','url'=>'https://example.invalid/post','news_version'=>'1000000'),$stage,$settings);
    sharedCheck(mucho7_news_source($stage.'/config/php7-news.json')['version']===1000001,'news version increments');
    try{mucho7_admin_action($pdo,1,'save_news',array('title'=>'Stale','body'=>'Stale edit','news_version'=>'1000000'),$stage,$settings);throw new RuntimeException('stale news accepted');}
    catch(RuntimeException $e){sharedCheck(strpos($e->getMessage(),'have already changed')!==false,'concurrent news edit protected');}
    mucho7_admin_action($pdo,1,'settings',array('site_url'=>'https://shared.example.invalid','enable_accounts'=>'1','enable_social'=>'1','enable_writes'=>'1'),$stage,$settings);
    $saved=require $stage.'/config/php7-local.php';sharedCheck($saved['password']===$settings['password'] && $saved['site_url']==='https://shared.example.invalid','settings preserve DB credentials');
    $upload=array('levelName'=>'Large native','levelString'=>str_repeat('a',300*1024),
        'songIDs'=>'1,2','sfxIDs'=>'3,4','levelInfo'=>'extras-data','settingsString'=>'settings-data','twoPlayer'=>'1');
    $id=$call(2,'uploadGJLevel22',$upload);sharedCheck(ctype_digit($id),'large editor upload');
    $download=$call(2,'downloadGJLevel22',array('levelID'=>$id,'extras'=>'1'));
    sharedCheck(strpos($download,':48:settings-data:')!==false && strpos($download,':26:extras-data#')!==false,'distinct editor metadata and extras roundtrip');
    $before=(int)$pdo->query('SELECT downloads FROM levels WHERE level_id='.(int)$id)->fetchColumn();
    $call(2,'downloadGJLevel22',array('levelID'=>$id));
    sharedCheck((int)$pdo->query('SELECT downloads FROM levels WHERE level_id='.(int)$id)->fetchColumn()===$before,'repeat download deduplicated');
    ob_start();mucho7_admin_backup($pdo,1);$dump=ob_get_clean();
    sharedCheck(strpos($dump,'CREATE TABLE `accounts`')!==false && strpos($dump,'INSERT INTO `accounts`')!==false,'streaming SQL backup contains native schema and data');
    sharedCheck(strpos($dump,'\\nDROP TABLE')===false,'SQL backup has real newline characters');
    sharedCheck(strpos($dump,$settings['password'])===false,'database password excluded from backup');
    $admin->exec('DROP DATABASE IF EXISTS mucho_php7_social_restore_ci');$admin->exec('CREATE DATABASE mucho_php7_social_restore_ci CHARACTER SET utf8mb4');
    $restore=new PDO($dsn.';dbname=mucho_php7_social_restore_ci','root',$password,$attrs);
    $restore->exec($dump);
    sharedCheck((int)$restore->query('SELECT COUNT(*) FROM accounts')->fetchColumn()===4,'backup restores accounts');
    sharedCheck($restore->query('SELECT level_info FROM php7_level_details WHERE level_id='.(int)$id)->fetchColumn()==='extras-data','backup restores editor extras');
    echo "PHP7_SHARED_SOCIAL_PRIVACY_ADMIN_UPGRADE_BACKUP_OK\n";
} finally {
    $admin->exec('DROP DATABASE IF EXISTS mucho_php7_social_ci');$admin->exec('DROP DATABASE IF EXISTS mucho_php7_social_restore_ci');
    foreach(glob($stage.'/config/*') as $file){unlink($file);}foreach(glob($stage.'/public/*') as $file){unlink($file);}
    rmdir($stage.'/config');rmdir($stage.'/public');rmdir($stage);
}
