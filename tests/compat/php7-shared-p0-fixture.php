<?php
/** Private CLI fixture for shared-p0-http.py. Never deploy this test helper. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('TEST_DB_HOST')!=='127.0.0.1'||!getenv('TEST_DB_ROOT_PASSWORD'))throw new RuntimeException('Disposable local MariaDB required.');
$root=dirname(__DIR__,2);$mode=isset($argv[1])?$argv[1]:'';$stage=isset($argv[2])?realpath($argv[2]):false;
if(!$stage||!preg_match('/^mucho-p0-http-[A-Za-z0-9_-]+$/D',basename($stage)))throw new RuntimeException('Invalid isolated fixture directory.');
foreach(array('bootstrap','runtime','schema','auth') as $module)require_once $root.'/compat/php7/'.$module.'.php';
require_once $root.'/compat/php7/domain.php';
$settingsPath=$stage.'/config/php7-local.php';
$options=array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC);
$dsn='mysql:host=127.0.0.1;port='.(int)(getenv('TEST_DB_PORT')?:3306).';charset=utf8mb4';
$password=(string)getenv('TEST_DB_ROOT_PASSWORD');$server=new PDO($dsn,'root',$password,$options);
if($mode==='create'){
    $name='mc_p0_http_'.bin2hex(random_bytes(6));
    $settings=array('host'=>'127.0.0.1','port'=>(int)(getenv('TEST_DB_PORT')?:3306),'database'=>$name,'username'=>'root','password'=>$password,
        'site_url'=>'https://fixture.invalid','auth_pepper'=>bin2hex(random_bytes(32)),
        'enable_accounts'=>true,'enable_registration'=>true,'enable_writes'=>true,'enable_social'=>true,'enable_cloudsave'=>true);
    // The Python runner creates this private config directory before bootstrap.
    mucho7_private_write($settingsPath,"<?php return ".var_export($settings,true).';');
    $server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    $db=new PDO($dsn.';dbname='.$name,'root',$password,$options);mucho7_create_schema($db);
    $insert=$db->prepare('INSERT INTO accounts(account_id,username,email,password_hash,gjp2_hash) VALUES(?,?,?,?,?)');
    foreach(array(1=>'P0Owner',2=>'P0Other',3=>'P0Banned',4=>'P0Inactive') as $id=>$username){
        $secret='P0Fixture-'.$id.'!';
        $insert->execute(array($id,$username,strtolower($username).'@example.invalid',password_hash($secret,PASSWORD_BCRYPT,array('cost'=>4)),password_hash(sha1($secret.'mI29fmAnxgTs'),PASSWORD_BCRYPT,array('cost'=>4))));
        $db->prepare('INSERT INTO profiles(account_id,stars) VALUES(?,?)')->execute(array($id,100*$id));
    }
    $db->exec('UPDATE accounts SET is_banned=1 WHERE account_id=3');$db->exec('UPDATE accounts SET is_active=0 WHERE account_id=4');
    do{$migration=mucho7_domain_upgrade($db,8);}while($migration['remaining']>0);
    do{$import=mucho7_domain_import_step($db,$stage,32);}while(!$import['done']);
    // Distinct user IDs catch accountID/userID namespace regressions.
    $db->exec('UPDATE profiles SET user_id=account_id+100');
    mucho7_private_write($stage.'/config/php7-complete.on','1');
    echo "P0_FIXTURE_CREATED\n";exit;
}
if(!is_file($settingsPath))throw new RuntimeException('Fixture is not initialized.');
$settings=require $settingsPath;$name=$settings['database'];
if(!preg_match('/^mc_p0_http_[a-f0-9]{12}$/D',$name))throw new RuntimeException('Invalid disposable database name.');
if($mode==='drop'){$server->exec('DROP DATABASE IF EXISTS '.$name);exit;}
$db=new PDO($dsn.';dbname='.$name,'root',$password,$options);
if($mode==='inspect'){
    $business=hash_init('sha256');
    foreach(array('accounts','profiles','levels','comments','account_comments','friends','messages','likes') as $table){
        $rows=$db->query('SELECT * FROM '.$table)->fetchAll();
        $encoded=array();foreach($rows as $row)$encoded[]=json_encode($row);
        sort($encoded,SORT_STRING);hash_update($business,$table.'|'.implode('|',$encoded));
    }
    echo json_encode(array(
        'levels'=>$db->query('SELECT level_id,account_id,name,level_version,level_data,level_info,settings_string,song_ids,sfx_ids,downloads,stars,is_deleted FROM levels ORDER BY level_id')->fetchAll(),
        'accounts'=>(int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn(),
        'comments'=>(int)$db->query('SELECT COUNT(*) FROM comments')->fetchColumn(),
        'profiles'=>(int)$db->query('SELECT COUNT(*) FROM profiles')->fetchColumn(),
        'download_slots'=>(int)$db->query('SELECT COUNT(*) FROM mucho_level_downloads')->fetchColumn(),
        'business_sha256'=>hash_final($business)
    ));exit;
}
if($mode==='read-only'||$mode==='writable'){
    $settings['enable_writes']=$mode==='writable';
    mucho7_private_write($settingsPath,"<?php return ".var_export($settings,true).';');exit;
}
if($mode==='friendships'){
    $db->exec('INSERT IGNORE INTO friends(account_id,friend_account_id) VALUES(1,2)');exit;
}
if($mode==='clear-friendships'){$db->exec('DELETE FROM friends');exit;}
throw new RuntimeException('Unknown fixture operation.');
