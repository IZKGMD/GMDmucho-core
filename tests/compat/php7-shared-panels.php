<?php
/** Missing admin modules and original importer over disposable databases. */
require_once $root.'/compat/php7/admin-ui/actions.php';
$GLOBALS['user']=$principal;$GLOBALS['pdo']=$db;$_POST=array();$_GET=array('tab'=>'search');
ob_start();require mucho7_compiled_include($root.'/public/admin/advanced-module.php');$advanced=ob_get_clean();
upgradeCheck(strpos($advanced,'Bulk Players')!==false,'Control v4 did not render');
mucho7_admin_ui_action($db,(int)$principal['account_id'],'v4-bulk-levels',array('ids'=>'1','operation'=>'feature'),$stage,$settings);
upgradeCheck((int)$db->query('SELECT featured FROM levels WHERE level_id=1')->fetchColumn()===1,'Bulk level action did not reach original handler');
$blocked=false;try{mucho7_admin_ui_action($db,(int)$principal['account_id'],'v4-bulk-players',array('ids'=>'1','operation'=>'delete','confirm'=>'DELETE'),$stage,$settings);}catch(RuntimeException $e){$blocked=true;}
upgradeCheck($blocked&&mucho7_admin_user($db,(int)$principal['account_id'])!==null,'Bulk tools removed linked owner access');
echo "PHP7_SHARED_ADVANCED_MODULE_ACTION_OWNER_GUARD_OK\n";
$pageLabels=require $root.'/compat/php7/admin-ui/pages.php';
foreach(array_keys($pageLabels) as $availablePage)upgradeCheck(canAdminPage($availablePage),'Missing shared admin navigation: '.$availablePage);
$script='/mc/admin.php';$publicBase='/mc/public';$adminNonce='fixtureNonce';
ob_start();require $root.'/compat/php7/admin-ui/migration.php';$migrationForm=mucho7_admin_ui_html(ob_get_clean());
upgradeCheck(strpos($migrationForm,'migrationConfirm')!==false&&strpos($migrationForm,'formaction="/mc/admin.php?page=migration"')!==false,'Migration form lost confirmation or shared mount');
upgradeCheck(strpos($migrationForm,'onclick=')===false,'Migration form contains a blocked inline event handler');
echo "PHP7_SHARED_ALL_33_NAVIGATION_AND_MIGRATION_FORM_OK\n";
if(!function_exists('mb_substr')){echo "SHARED_IMPORT_SKIPPED_LOCAL_RUNTIME_WITHOUT_MBSTRING\n";return;}
require_once $root.'/compat/php7/migration.php';
$sourceDatabase=$database.'_source';$server->exec('CREATE DATABASE '.$sourceDatabase.' CHARACTER SET utf8mb4');
try{
    $source=new PDO($dsn.';dbname='.$sourceDatabase,'root',$password,$options);
    $source->exec('CREATE TABLE accounts(accountID BIGINT PRIMARY KEY,userName VARCHAR(64),password VARCHAR(255),gjp2 VARCHAR(255),email VARCHAR(255),isActive INT)');
    $source->prepare('INSERT INTO accounts VALUES(1001,?,?,?,?,1)')->execute(array('MigratedFixture',$hash,$hash,'migrated@example.invalid'));
    $source->exec('CREATE TABLE users(extID VARCHAR(64),stars INT DEFAULT 0,moons INT DEFAULT 0,diamonds INT DEFAULT 0,coins INT DEFAULT 0,userCoins INT DEFAULT 0,demons INT DEFAULT 0,creatorPoints INT DEFAULT 0,icon INT DEFAULT 1,iconType INT DEFAULT 0,color1 INT DEFAULT 1,color2 INT DEFAULT 2,accGlow INT DEFAULT 0,isBanned INT DEFAULT 0)');
    $source->exec("INSERT INTO users(extID,stars) VALUES('1001',9)");
    $source->exec('CREATE TABLE levels(levelID BIGINT PRIMARY KEY,levelName VARCHAR(64),levelDesc TEXT,levelString MEDIUMTEXT,levelVersion INT,gameVersion INT,levelLength INT,audioTrack INT,extID BIGINT)');
    $source->exec("INSERT INTO levels VALUES(1001,'Imported fixture','ZGVzYw==','kS1,1,2;',1,22,1,0,1001)");
    $original=hash('sha256',json_encode($source->query('SELECT * FROM accounts')->fetchAll()));
    $readOnly=\MuchoCore\Migration\SharedMigrationService::connectSource($host,(int)$port,$sourceDatabase,'root',$password);
    $before=(int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
    $preview=mucho7_shared_migrate($db,$readOnly,$stage);
    upgradeCheck($preview['preflight']['accounts']===1&&(int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn()===$before,'Read-only migration preview changed target accounts');
    $imported=mucho7_shared_migrate($db,$readOnly,$stage,true);
    upgradeCheck(is_file($imported['backup']['file'])&&$imported['stats']['accounts_created']===1,'Migration did not back up and import the source account');
    upgradeCheck((int)$db->query("SELECT COUNT(*) FROM levels WHERE name='Imported fixture'")->fetchColumn()===1,'Migration omitted source level');
    upgradeCheck($db->query('SELECT password_hash FROM accounts WHERE account_id=1')->fetchColumn()===$hash,'Migration changed existing owner credentials');
    $again=mucho7_shared_migrate($db,$readOnly,$stage,true);
    upgradeCheck($again['stats']['accounts_created']===0&&(int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn()===$before+1,'Repeated migration duplicated source accounts');
    upgradeCheck(hash('sha256',json_encode($source->query('SELECT * FROM accounts')->fetchAll()))===$original,'Migration modified source accounts');
    echo "PHP7_SHARED_CVOLTON_PREVIEW_BACKUP_IMPORT_IDEMPOTENCE_SOURCE_READONLY_OK\n";
}finally{$server->exec('DROP DATABASE IF EXISTS '.$sourceDatabase);}
