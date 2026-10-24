<?php
/** Preserve a populated native installation while applying original schema. */
declare(strict_types=1);
$root=dirname(__DIR__,2);
foreach(array('bootstrap','runtime','schema','auth','news','admin') as $module)require_once $root.'/compat/php7/'.$module.'.php';
require_once $root.'/compat/php7/admin-ui/bridge.php';
require_once $root.'/compat/php7/domain.php';
require_once $root.'/compat/php7/core-update.php';
function upgradeCheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
$host=getenv('TEST_DB_HOST');$password=getenv('TEST_DB_ROOT_PASSWORD');$port=getenv('TEST_DB_PORT')?:3306;
if($host!=='127.0.0.1'||!is_string($password)||$password==='')throw new RuntimeException('Requires disposable local MariaDB.');
$dsn='mysql:host='.$host.';port='.(int)$port.';charset=utf8mb4';
$options=array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC);
$server=new PDO($dsn,'root',$password,$options);$database='mc_native_upgrade_'.bin2hex(random_bytes(5));
$stage=sys_get_temp_dir().'/'.$database;mkdir($stage.'/config',0700,true);$legacy=in_array('--legacy',$argv,true);
try{
    $server->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4');
    $db=new PDO($dsn.';dbname='.$database,'root',$password,$options);mucho7_create_schema($db);
    $hash=password_hash('FixtureOwner1!',PASSWORD_BCRYPT,array('cost'=>4));
    $db->prepare("INSERT INTO accounts(account_id,username,email,password_hash,gjp2_hash,role_id) VALUES(1,'Owner','owner@example.invalid',?,?,4)")->execute(array($hash,password_hash(sha1('FixtureOwner1!mI29fmAnxgTs'),PASSWORD_BCRYPT,array('cost'=>4))));
    $db->prepare("INSERT INTO accounts(account_id,username,email,password_hash,gjp2_hash,role_id) VALUES(2,'Member','member@example.invalid',?,?,1),(3,'Other','other@example.invalid',?,?,1)")->execute(array($hash,$hash,$hash,$hash));
    $db->exec('INSERT INTO profiles(account_id,stars,icon_id) VALUES(1,123,23),(2,5,17),(3,0,1)');
    $details=array('ship'=>24,'color3'=>-1,'message_state'=>2,'comment_history_state'=>2,'youtube'=>'channel','demon_info'=>'1,2,3');
    $db->prepare('INSERT INTO php7_user_details(account_id,payload) VALUES(1,?)')->execute(array(json_encode($details)));
    $db->exec("INSERT INTO levels(account_id,name,description,level_data) VALUES(1,'Keep level','Keep description','binary-level-data')");
    $db->exec("INSERT INTO php7_comments(kind,level_id,account_id,content) VALUES(1,1,1,'Keep comment'),(2,0,1,'Keep profile comment'),(0,0,2,'Keep early profile comment')");
    $db->exec('INSERT INTO php7_votes(type,item_id,account_id,value) VALUES(1,1,2,1),(2,1,2,-1),(3,2,2,1)');
    $db->exec('INSERT INTO php7_friends(account_id,friend_account_id,is_new) VALUES(1,2,1),(2,1,0)');
    $db->exec('CREATE TABLE IF NOT EXISTS php7_friendships(account_low BIGINT,account_high BIGINT,low_new TINYINT,high_new TINYINT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('INSERT INTO php7_friendships(account_low,account_high,low_new,high_new) VALUES(1,3,0,1)');
    if($legacy){$db->exec('DROP TABLE php7_friend_requests');$db->exec("CREATE TABLE php7_friend_requests(id BIGINT PRIMARY KEY,sender_id BIGINT,receiver_id BIGINT,comment TEXT,is_new TINYINT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");$db->exec("INSERT INTO php7_friend_requests(id,sender_id,receiver_id,comment,is_new) VALUES(7,3,2,'Pending',1)");}
    else $db->exec("INSERT INTO php7_friend_requests(id,account_id,to_account_id,comment,is_read) VALUES(7,3,2,'Pending',2)");
    $db->exec('INSERT INTO php7_blocks(account_id,blocked_account_id) VALUES(3,2)');
    $db->exec("INSERT INTO php7_messages(id,account_id,to_account_id,subject,body,is_read,is_receiver_deleted) VALUES(5,1,2,'c3ViamVjdA==','Ym9keQ==',1,1)");
    $plain="Cloud\0save\xff;21;30;a;a";
    $db->prepare('INSERT INTO php7_cloud_saves(account_id,save_data,sha256) VALUES(1,?,?)')->execute(array($plain,hash('sha256',$plain)));
    mucho7_admin_registry_install($db);
    $principal=mucho7_admin_user($db,1);
    $db->exec('INSERT INTO php7_admin_security(account_id,last_step,auth_version) VALUES(1,42,3) ON DUPLICATE KEY UPDATE last_step=42,auth_version=3');
    $rounds=0;do{$result=mucho7_domain_upgrade($db,8);if(++$rounds>25)throw new RuntimeException('Upgrade did not converge');}while($result['remaining']>0);
    upgradeCheck($db->query('SELECT password_hash FROM accounts WHERE account_id=1')->fetchColumn()===$hash,'Upgrade replaced credentials');
    upgradeCheck((int)$db->query('SELECT stars FROM profiles WHERE account_id=1')->fetchColumn()===123,'Upgrade replaced profile');
    upgradeCheck($db->query('SELECT level_data FROM levels WHERE level_id=1')->fetchColumn()==='binary-level-data','Upgrade replaced level');
    upgradeCheck($db->query('SELECT content FROM php7_comments WHERE id=1')->fetchColumn()==='Keep comment','Upgrade changed native comments');
    upgradeCheck(mucho7_admin_user($db,1)===$principal,'Upgrade changed panel identity');
    upgradeCheck((int)mucho7_admin_security_row($db,1)['auth_version']===3,'Upgrade changed administrator factor revision');
    $again=mucho7_domain_upgrade($db,8);upgradeCheck($again['remaining']===0&&$again['applied']===0,'Upgrade is not idempotent');
    $rounds=0;do{$import=mucho7_domain_import_step($db,$stage,1);if(++$rounds>100)throw new RuntimeException('Import did not converge');}while(!$import['done']);
    upgradeCheck(mucho7_domain_complete($db),'Import completion marker missing');
    upgradeCheck((int)$db->query('SELECT cube FROM profiles WHERE account_id=1')->fetchColumn()===23,'Native icon was replaced');
    upgradeCheck((int)$db->query('SELECT ship FROM profiles WHERE account_id=1')->fetchColumn()===24,'Native ship was omitted');
    upgradeCheck((int)$db->query('SELECT color3 FROM profiles WHERE account_id=1')->fetchColumn()===-1,'Signed native glow color changed');
    upgradeCheck((int)$db->query('SELECT messages_state FROM accounts WHERE account_id=1')->fetchColumn()===2,'Native privacy was lost');
    upgradeCheck($db->query('SELECT content FROM comments WHERE id=1')->fetchColumn()==='Keep comment','Level comment import');
    upgradeCheck((int)$db->query('SELECT COUNT(*) FROM account_comments')->fetchColumn()===2,'Profile comment kinds were lost');
    upgradeCheck((int)$db->query('SELECT COUNT(*) FROM likes')->fetchColumn()===3,'Vote identities were lost');
    upgradeCheck((int)$db->query('SELECT COUNT(*) FROM friends')->fetchColumn()===2,'Friendship directions were lost');
    upgradeCheck((int)$db->query('SELECT friend_account_id FROM friends WHERE account_id=1')->fetchColumn()===($legacy?3:2),'Inactive historical friendship storage was resurrected');
    upgradeCheck((int)$db->query('SELECT is_receiver_deleted FROM messages WHERE id=5')->fetchColumn()===1,'Private message deletion flag changed');
    upgradeCheck((new \MuchoCore\Social\RelationshipRepository($db))->requestCount(2,false)===($legacy?1:0),'Hidden friend request became visible');
    putenv('MUCHO_SHARED_HOSTING=1');putenv('MUCHO_CLOUDSAVE_KEY_FILE='.$stage.'/config/cloudsave.key');
    $saves=new \MuchoCore\CloudSave\CloudSaveRepository($db);upgradeCheck($saves->load(1)===$plain,'Encrypted native save changed bytes');
    upgradeCheck(mucho7_domain_import_step($db,$stage)['done'],'Repeated import was not idempotent');
    $request=new \MuchoCore\Http\Request('POST','/getGJChallenges',array(),array(),array('REMOTE_ADDR'=>'127.0.0.1'));
    $app=new \MuchoCore\Core\Application($db,$request);
    $response=$app->handle(new \MuchoCore\Http\Request('GET','/checkIfServerOnline',array(),array(),array()));
    upgradeCheck($response->body==='1','Compiled application/router does not dispatch');
    $post=array('userName'=>'Owner','password'=>'FixtureOwner1!','gameVersion'=>'22','binaryVersion'=>'51');$_POST=$post;
    $response=$app->handle(new \MuchoCore\Http\Request('POST','/loginGJAccount22',array(),$post,array('REMOTE_ADDR'=>'127.0.0.1')));
    upgradeCheck($response->body==='1,1','Original login lost native account/user IDs: '.$response->body);
    $post=array('type'=>'0','str'=>'Keep level','gameVersion'=>'22','binaryVersion'=>'51');$_POST=$post;
    $response=$app->handle(new \MuchoCore\Http\Request('POST','/getGJLevels21',array(),$post,array('REMOTE_ADDR'=>'127.0.0.1')));
    upgradeCheck(strpos($response->body,'Keep level')!==false,'Original level search cannot read native level: '.$response->body);
    $post=array('levelID'=>'1','gameVersion'=>'22','binaryVersion'=>'51');$_POST=$post;
    $response=$app->handle(new \MuchoCore\Http\Request('POST','/getGJComments21',array(),$post,array('REMOTE_ADDR'=>'127.0.0.1')));
    upgradeCheck(strpos($response->body,base64_encode('Keep comment'))!==false,'Original comment route lost imported content: '.$response->body);
    // The owner resumes a partially finished transition and activation is last.
    $settings=array('site_url'=>'https://fixture.invalid','enable_accounts'=>true,'enable_writes'=>true,'enable_registration'=>true,'enable_cloudsave'=>true,'enable_social'=>true,'enable_native_news'=>false);
    mkdir($stage.'/compat/php7',0700,true);file_put_contents($stage.'/config/php7-core-build.json','{}');
    mucho7_private_write($stage.'/config/php7-core-transition.json',json_encode(array('started_at'=>time()-40)));
    // domain.php is already loaded; this fixture uses the tested compiled source.
    file_put_contents($stage.'/compat/php7/domain.php',"<?php // fixture: runtime already loaded\n");
    do{$activation=mucho7_core_upgrade($db,1,$stage,$settings);}while(!$activation['done']);
    upgradeCheck($activation['done']&&is_file($stage.'/config/php7-complete.on')&&!is_file($stage.'/config/php7-core-transition.json'),'Activation order failed');
    $GLOBALS['user']=$principal;$GLOBALS['pdo']=$db;$_SESSION=array('csrf'=>'fixture');$_GET=array();$_POST=array();
    foreach(array('clans','contentpacks','plugins','intelligence') as $page){ob_start();require mucho7_compiled_include($root.'/public/admin/pages/'.$page.'.php');if($page==='plugins')renderCustomPluginsPage($db);if($page==='intelligence')renderIntelligenceScalePage($db);$html=ob_get_clean();upgradeCheck(strlen($html)>100,'Original admin page empty: '.$page);}
    $readSettings=$settings;$readSettings['enable_writes']=false;
    $_SERVER=array('REMOTE_ADDR'=>'127.0.0.1');$_GET=array();$_POST=array('levelID'=>'1');
    $before=(int)$db->query('SELECT downloads FROM levels WHERE level_id=1')->fetchColumn();
    $result=mucho7_domain_handle($db,'/downloadGJLevel22','POST',$_POST,$readSettings,$stage);
    upgradeCheck(strpos($result->body,'binary-level-data')!==false,'Read-only level download unavailable');
    upgradeCheck((int)$db->query('SELECT downloads FROM levels WHERE level_id=1')->fetchColumn()===$before,'Read-only request updated counters');
    $result=mucho7_domain_handle($db,'/uploadGJLevel21','POST',array(),$readSettings,$stage);upgradeCheck($result->body==='-1','Read-only writes allowed');
    require __DIR__.'/php7-shared-worker.php';
    echo 'PHP7_ORIGINAL_SCHEMA_UPGRADE_PRESERVES_NATIVE_DATA_OK '.($legacy?'LEGACY':'CURRENT')."\n";
}finally{$server->exec('DROP DATABASE IF EXISTS '.$database);$remove=function($path)use(&$remove){if(is_dir($path)){foreach(new DirectoryIterator($path) as $item)if(!$item->isDot())$remove($item->getPathname());rmdir($path);}else unlink($path);};$remove($stage);}
