<?php
declare(strict_types=1);
use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Http\Request;
use MuchoCore\Creator\CreatorHubController;
use MuchoCore\Creator\CreatorHubService;
spl_autoload_register(static function(string $class):void {if(str_starts_with($class,'MuchoCore\\'))require dirname(__DIR__,2).'/src/'.str_replace('\\','/',substr($class,10)).'.php';});
function ck(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
function fails(callable $fn,string $message):void {try{$fn();}catch(RuntimeException){return;}throw new RuntimeException($message);}
$host=getenv('TEST_DB_HOST')?:'127.0.0.1';$port=getenv('TEST_DB_PORT')?:'3306';$pass=getenv('TEST_DB_ROOT_PASSWORD')?:'';
$connect=static fn(string $name=''):PDO=>new PDO("mysql:host=$host;port=$port".($name!==''?";dbname=$name":''),'root',$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(($argv[1]??'')==='--race'){
 $db=$connect($argv[2]);$hub=new CreatorHubService($db);usleep(200000);
 try{$hub->submit($hub->gameActor(5),(int)$argv[3],'Parallel request.');echo 'accepted';}catch(RuntimeException){echo 'denied';}exit;
}
$root=$connect();$name='mc_creator_'.bin2hex(random_bytes(5));$root->exec("CREATE DATABASE $name");
try {
 $db=$connect($name);$db->exec('CREATE TABLE roles(id BIGINT UNSIGNED PRIMARY KEY,code VARCHAR(32)) ENGINE=InnoDB');$db->exec("INSERT INTO roles VALUES(1,'user'),(2,'moderator')");
 $db->exec('CREATE TABLE accounts(account_id BIGINT UNSIGNED PRIMARY KEY,username VARCHAR(32),password_hash VARCHAR(255),gjp2_hash VARCHAR(255),is_active INT DEFAULT 1,is_banned INT DEFAULT 0,role_id BIGINT UNSIGNED DEFAULT 1) ENGINE=InnoDB');
 $db->exec('CREATE TABLE profiles(user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,account_id BIGINT UNSIGNED UNIQUE) ENGINE=InnoDB');
 $db->exec('CREATE TABLE levels(level_id BIGINT UNSIGNED PRIMARY KEY,account_id BIGINT UNSIGNED,name VARCHAR(64),level_data LONGTEXT,level_version INT DEFAULT 1,stars INT DEFAULT 0,rate_tier INT DEFAULT 0,is_deleted INT DEFAULT 0,is_unlisted INT DEFAULT 0,unlisted2 INT DEFAULT 0) ENGINE=InnoDB');
 foreach(require dirname(__DIR__,2).'/database/migrations/20261013_002_creator_hub.php' as $sql)$db->exec($sql);
 $credential=str_repeat('a',40);$hash=password_hash($credential,PASSWORD_BCRYPT,['cost'=>4]);$insert=$db->prepare('INSERT INTO accounts(account_id,username,password_hash,gjp2_hash,role_id) VALUES(?,?,?,?,?)');
 for($i=1;$i<=5;$i++){$insert->execute([$i,'User'.$i,'',$hash,in_array($i,[3,4],true)?2:1]);$db->exec("INSERT INTO profiles(account_id) VALUES($i)");}
 $l=$db->prepare('INSERT INTO levels(level_id,account_id,name,level_data) VALUES(?,?,?,?)');foreach([[101,1],[102,1],[103,1],[104,1],[201,2],[301,3],[501,5],[502,5],[503,5],[504,5]] as [$id,$owner])$l->execute([$id,$owner,'Level'.$id,'kS38,1;1,2;']);
 $hub=new CreatorHubService($db);$creator=$hub->gameActor(1);$other=$hub->gameActor(2);$mod=$hub->gameActor(3);$mod2=$hub->gameActor(4);$admin=$hub->adminActor(1,'Admin');
 $controller=new CreatorHubController(new AccountAuthenticator($db),$hub);
 $anon=$controller->handle(new Request('POST','/extensions/creator-hub',[],['action'=>'list'],[]));ck($anon->status===401,'Anonymous access allowed');
 $api=$controller->handle(new Request('POST','/extensions/creator-hub',[],['accountID'=>'1','gjp2'=>$credential,'action'=>'list'],[]));ck(json_decode($api->body,true)['ok'],'Authenticated API failed: '.$api->body);
 fails(fn()=>$hub->submit($creator,201,'Not mine'),'Other creator level accepted');
 $db->exec('UPDATE levels SET is_unlisted=1 WHERE level_id=104');fails(fn()=>$hub->submit($creator,104,''),'Unlisted level accepted');$db->exec('UPDATE levels SET is_unlisted=0 WHERE level_id=104');
 $r=$hub->submit($creator,101,'First review request.');$id=$r['id'];ck($r['state']==='submitted','Initial state wrong');
 $again=$hub->submit($creator,101,'Repeated click');ck($again['id']===$id&&count($again['history'])===1,'Repeated submit duplicated request');
 fails(fn()=>$hub->detail($other,$id),'Private request leaked');fails(fn()=>$hub->listing($other,'queue'),'Player saw moderator queue');
 $self=$hub->submit($mod,301,'My level');fails(fn()=>$hub->claim($mod,$self['id'],$self['revision']),'Self review allowed');
 $claimed=$hub->claim($mod,$id,$r['revision']);ck($claimed['state']==='in_review','Claim failed');
 fails(fn()=>$hub->claim($mod2,$id,$r['revision']),'Second moderator stole request');
 fails(fn()=>$hub->decide($mod,$id,$r['revision'],'approved','Good'),'Stale revision accepted');
 fails(fn()=>$hub->decide($mod,$id,$claimed['revision'],'rejected','No'),'Meaningless rejection allowed');
 $changed=$hub->decide($mod,$id,$claimed['revision'],'changes_requested','Please improve the transitions.');
 fails(fn()=>$hub->submit($creator,101,''),'Unchanged level resubmitted after feedback');
 $db->exec("UPDATE levels SET level_data='updated content',level_version=2 WHERE level_id=101");$new=$hub->submit($creator,101,'Transitions updated.');ck($new['cycle']===2,'New revision not tracked');
 $taken=$hub->claim($mod,$id,$new['revision']);$db->exec("UPDATE levels SET level_data='another edit' WHERE level_id=101");
 fails(fn()=>$hub->decide($mod,$id,$taken['revision'],'approved','Looks good.'),'Changed content approved');ck(!$db->inTransaction(),'Failed decision left open transaction');
 $fresh=$hub->submit($creator,101,'Review this version instead.');$taken=$hub->claim($mod,$id,$fresh['revision']);$approved=$hub->decide($mod,$id,$taken['revision'],'approved','Recommended for Rating Studio.');
 ck($approved['state']==='approved'&&$approved['stars']===0,'Recommendation changed native rating');
 fails(fn()=>$hub->submit($creator,101,''),'Resubmit cooldown bypassed');
 $db->exec('UPDATE levels SET stars=7 WHERE level_id=101');ck($hub->detail($creator,$id)['stars']===7,'Rating Studio result not visible');
 $req=$hub->submit($creator,102,'Withdraw test');fails(fn()=>$hub->withdraw($other,$req['id'],$req['revision']),'Another creator withdrew request');
 $assigned=$hub->claim($mod,$req['id'],$req['revision']);$released=$hub->release($admin,$req['id'],$assigned['revision']);ck($released['state']==='submitted','Admin cannot release abandoned review');
 $withdrawn=$hub->withdraw($creator,$req['id'],$released['revision']);ck($withdrawn['state']==='withdrawn','Withdraw failed');
 $actor5=$hub->gameActor(5);$hub->submit($actor5,501,'One');$hub->submit($actor5,502,'Two');
 if(function_exists('proc_open')){
  $children=[];foreach([503,504] as $level){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'--race',$name,(string)$level],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);ck(is_resource($proc),'Cannot start concurrency test');fclose($pipes[0]);$children[]=[$proc,$pipes];}
  $results=[];foreach($children as [$proc,$pipes]){$results[]=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);ck(proc_close($proc)===0,'Concurrent child failed: '.$error);}
  sort($results);ck($results===['accepted','denied'],'Concurrent submit exceeded active quota');
 }
 ck((int)$db->query("SELECT COUNT(*) FROM mucho_creator_requests WHERE account_id=5 AND state IN ('submitted','in_review')")->fetchColumn()<=3,'Active quota failed');
 ck(count($hub->listing($mod,'archive')['items'])>=2,'Archive missing decisions');
 echo "Creator Hub ownership, auth, review assignment, snapshots, history, cooldown, rollback and concurrent quota: OK\n";
}finally{$root->exec("DROP DATABASE $name");}
