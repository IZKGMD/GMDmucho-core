<?php
declare(strict_types=1);
use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Http\Request;
use MuchoCore\Social\LiveSocialController;
spl_autoload_register(static function(string $class):void {
 if(str_starts_with($class,'MuchoCore\\'))require dirname(__DIR__,2).'/src/'.str_replace('\\','/',substr($class,10)).'.php';
});
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$host=getenv('TEST_DB_HOST')?:'127.0.0.1';$port=getenv('TEST_DB_PORT')?:'3306';
$root=new PDO("mysql:host=$host;port=$port",'root',getenv('TEST_DB_ROOT_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='mc_social_'.bin2hex(random_bytes(5));$root->exec("CREATE DATABASE $name");
try {
 $db=new PDO("mysql:host=$host;port=$port;dbname=$name",'root',getenv('TEST_DB_ROOT_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec('CREATE TABLE accounts(account_id BIGINT UNSIGNED PRIMARY KEY,username VARCHAR(32),password_hash VARCHAR(255),gjp2_hash VARCHAR(255),is_active INT DEFAULT 1,is_banned INT DEFAULT 0) ENGINE=InnoDB');
 $db->exec('CREATE TABLE profiles(user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,account_id BIGINT UNSIGNED UNIQUE,stars INT DEFAULT 0,demons INT DEFAULT 0,diamonds INT DEFAULT 0) ENGINE=InnoDB');
 $db->exec('CREATE TABLE friends(account_id BIGINT UNSIGNED,friend_account_id BIGINT UNSIGNED,PRIMARY KEY(account_id,friend_account_id)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE blocks(account_id BIGINT UNSIGNED,blocked_account_id BIGINT UNSIGNED,PRIMARY KEY(account_id,blocked_account_id)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE levels(level_id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
 foreach(require dirname(__DIR__,2).'/database/migrations/20261013_001_social_live.php' as $sql)$db->exec($sql);
 $credential=str_repeat('a',40);$hash=password_hash($credential,PASSWORD_BCRYPT,['cost'=>4]);
 $insert=$db->prepare('INSERT INTO accounts(account_id,username,password_hash,gjp2_hash) VALUES(?,?,?,?)');
 for($i=1;$i<=3;$i++){$insert->execute([$i,'User'.$i,'',$hash]);$db->exec("INSERT INTO profiles(account_id,stars) VALUES($i,".($i===1?1000:0).')');}
 $db->exec('INSERT INTO friends VALUES(1,2),(2,1)');$db->exec('INSERT INTO levels VALUES(100)');
 $controller=new LiveSocialController($db,new AccountAuthenticator($db));
 $call=static function(int $id,string $action,array $args=[])use($controller,$credential):array {
  $r=$controller->handle(new Request('POST','/api/social/live',[],['accountID'=>(string)$id,'gjp2'=>$credential,'action'=>$action]+$args,[]));
  return json_decode($r->body,true,512,JSON_THROW_ON_ERROR);
 };
 check(!$call(0,'friends')['ok'],'Anonymous request accepted');
 check($call(1,'heartbeat',['activity'=>'editor'])['ok'],'Heartbeat failed');
 check($call(2,'friends')['data']['friends'][0]['activity']==='editor','Friend activity hidden');
 check($call(3,'profile',['targetID'=>'1'])['data']['activity']==='offline','Presence leaked to unrelated account');
 check(!$call(1,'heartbeat',['activity'=>'invalid'])['ok'],'Invalid activity accepted');
 check($call(2,'react',['targetID'=>'1','achievement'=>'stars_1000','reaction'=>'heart'])['ok'],'Unlocked reaction rejected');
 $call(2,'react',['targetID'=>'1','achievement'=>'stars_1000','reaction'=>'fire']);
 check((int)$db->query('SELECT COUNT(*) FROM mucho_achievement_reactions')->fetchColumn()===1,'Reaction duplicated');
 check(!$call(2,'react',['targetID'=>'1','achievement'=>'stars_10000','reaction'=>'heart'])['ok'],'Locked achievement accepted');
 check(!$call(1,'room-create',['levelID'=>'999'])['ok'],'Unknown level accepted');
 $room=$call(1,'room-create',['levelID'=>'100']);check($room['ok'],'Room creation failed');$id=(string)$room['data']['id'];
 check(!$call(3,'room',['roomID'=>$id])['ok'],'Room leaked to outsider');
 check($call(1,'invite',['roomID'=>$id,'targetID'=>'2'])['ok'],'Friend invite rejected');
 check(!$call(1,'invite',['roomID'=>$id,'targetID'=>'3'])['ok'],'Nonfriend invite accepted');
 check(count($call(2,'invites')['data']['invites'])===1,'Invitation missing');
 check($call(2,'accept',['roomID'=>$id])['ok'],'Invitation acceptance failed');
 check(count($call(2,'room',['roomID'=>$id])['data']['members'])===2,'Member not joined');
 check(!$call(2,'invite',['roomID'=>$id,'targetID'=>'1'])['ok'],'Nonhost invitation accepted');
 $db->exec('INSERT INTO blocks VALUES(2,1)');
 check(!$call(2,'react',['targetID'=>'1','achievement'=>'stars_1000','reaction'=>'heart'])['ok'],'Blocked reaction accepted');
 $call(1,'leave',['roomID'=>$id]);check(!$call(2,'room',['roomID'=>$id])['ok'],'Host leave did not close room');
 $db->exec('DELETE FROM blocks');$new=$call(1,'room-create',['levelID'=>'100'])['data']['id'];$db->exec("UPDATE mucho_social_rooms SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=$new");
 check(!$call(1,'room',['roomID'=>(string)$new])['ok'],'Expired room accessible');
 echo "Social presence, milestones, friends, invitations and permissions: OK\n";
} finally {$root->exec("DROP DATABASE $name");}
