<?php
/** Preserve a populated native installation while applying original schema. */
declare(strict_types=1);
$root=dirname(__DIR__,2);
foreach(array('bootstrap','runtime','schema','auth','news','admin') as $module)require_once $root.'/compat/php7/'.$module.'.php';
require_once $root.'/compat/php7/admin-registry.php';
require_once $root.'/compat/php7/domain.php';
function upgradeCheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
$host=getenv('TEST_DB_HOST');$password=getenv('TEST_DB_ROOT_PASSWORD');$port=getenv('TEST_DB_PORT')?:3306;
if($host!=='127.0.0.1'||!is_string($password)||$password==='')throw new RuntimeException('Requires disposable local MariaDB.');
$dsn='mysql:host='.$host.';port='.(int)$port.';charset=utf8mb4';
$options=array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC);
$server=new PDO($dsn,'root',$password,$options);$database='mc_native_upgrade_'.bin2hex(random_bytes(5));
try{
    $server->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4');
    $db=new PDO($dsn.';dbname='.$database,'root',$password,$options);mucho7_create_schema($db);
    $hash=password_hash('FixtureOwner1!',PASSWORD_BCRYPT,array('cost'=>4));
    $db->prepare("INSERT INTO accounts(account_id,username,email,password_hash,gjp2_hash,role_id) VALUES(1,'Owner','owner@example.invalid',?,?,4)")->execute(array($hash,password_hash(sha1('FixtureOwner1!mI29fmAnxgTs'),PASSWORD_BCRYPT,array('cost'=>4))));
    $db->exec('INSERT INTO profiles(account_id,stars) VALUES(1,123)');
    $db->exec("INSERT INTO levels(account_id,name,description,level_data) VALUES(1,'Keep level','Keep description','binary-level-data')");
    $db->exec("INSERT INTO php7_comments(kind,level_id,account_id,content) VALUES(1,1,1,'Keep comment')");
    mucho7_admin_registry_install($db);
    $principal=mucho7_admin_user($db,1);
    $rounds=0;do{$result=mucho7_domain_upgrade($db,8);if(++$rounds>25)throw new RuntimeException('Upgrade did not converge');}while($result['remaining']>0);
    upgradeCheck($db->query('SELECT password_hash FROM accounts WHERE account_id=1')->fetchColumn()===$hash,'Upgrade replaced credentials');
    upgradeCheck((int)$db->query('SELECT stars FROM profiles WHERE account_id=1')->fetchColumn()===123,'Upgrade replaced profile');
    upgradeCheck($db->query('SELECT level_data FROM levels WHERE level_id=1')->fetchColumn()==='binary-level-data','Upgrade replaced level');
    upgradeCheck($db->query('SELECT content FROM php7_comments WHERE id=1')->fetchColumn()==='Keep comment','Upgrade changed native comments');
    upgradeCheck(mucho7_admin_user($db,1)===$principal,'Upgrade changed panel identity');
    $again=mucho7_domain_upgrade($db,8);upgradeCheck($again['remaining']===0&&$again['applied']===0,'Upgrade is not idempotent');
    $request=new \MuchoCore\Http\Request('POST','/getGJChallenges',array(),array(),array('REMOTE_ADDR'=>'127.0.0.1'));
    $app=new \MuchoCore\Core\Application($db,$request);
    $response=$app->handle(new \MuchoCore\Http\Request('GET','/checkIfServerOnline',array(),array(),array()));
    upgradeCheck($response->body==='1','Compiled application/router does not dispatch');
    echo "PHP7_ORIGINAL_SCHEMA_UPGRADE_PRESERVES_NATIVE_DATA_OK\n";
}finally{$server->exec('DROP DATABASE IF EXISTS '.$database);}
