<?php
/** Exercise distinct panel identities, invitations and RBAC on SQLite/MySQL. */
declare(strict_types=1);
$root=dirname(__DIR__,2);
foreach(array('bootstrap','runtime','schema','auth','news','admin') as $module)require_once $root.'/compat/php7/'.$module.'.php';
require_once $root.'/compat/php7/admin-ui/bridge.php';
require_once $root.'/compat/php7/admin-ui/actions.php';
function arCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
function arReject($call,$message){try{$call();}catch(RuntimeException $e){return;}throw new LogicException('Accepted: '.$message);}
class RegistryPDO extends PDO {
    #[\ReturnTypeWillChange]
    public function prepare($sql,$options=array()){return parent::prepare(str_replace(array(' FOR UPDATE','INSERT IGNORE'),array('','INSERT OR IGNORE'),$sql),$options);}
    #[\ReturnTypeWillChange]
    public function query($sql,...$args){return parent::query(str_replace(' FOR UPDATE','',$sql),...$args);}
    #[\ReturnTypeWillChange]
    public function exec($sql){if(strpos($sql,'ENGINE=InnoDB')!==false)return 0;return parent::exec(str_replace('INSERT IGNORE','INSERT OR IGNORE',$sql));}
}
$mysql=in_array('--mysql',$argv,true);$server=null;$database='mucho_php7_admin_registry_ci';
try {
 if($mysql){
    $password=getenv('TEST_DB_ROOT_PASSWORD');if($password===false)throw new RuntimeException('TEST_DB_ROOT_PASSWORD required');
    $dsn='mysql:host='.(getenv('TEST_DB_HOST')?:'127.0.0.1').';port='.(getenv('TEST_DB_PORT')?:3306).';charset=utf8mb4';
    $attrs=array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC);
    $server=new PDO($dsn,'root',$password,$attrs);$server->exec('DROP DATABASE IF EXISTS '.$database);$server->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4');
    $pdo=new PDO($dsn.';dbname='.$database,'root',$password,$attrs);mucho7_create_schema($pdo);mucho7_upgrade_features($pdo);
 }else{
    $pdo=new RegistryPDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('UTC_TIMESTAMP',function(){return gmdate('Y-m-d H:i:s');});
    $pdo->exec("CREATE TABLE roles(id INTEGER PRIMARY KEY,code TEXT);INSERT INTO roles VALUES(1,'user'),(2,'moderator'),(4,'owner');
    CREATE TABLE accounts(account_id INTEGER PRIMARY KEY,username TEXT UNIQUE,email TEXT,password_hash TEXT,gjp2_hash TEXT,role_id INTEGER,is_active INTEGER DEFAULT 1,is_banned INTEGER DEFAULT 0);
    CREATE TABLE php7_auth_events(id INTEGER PRIMARY KEY,action TEXT,ip_hash TEXT,username_hash TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE php7_admin_audit(id INTEGER PRIMARY KEY,account_id INTEGER,action TEXT,target_id INTEGER,details TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE php7_admin_security(account_id INTEGER PRIMARY KEY,totp_secret TEXT,last_step INTEGER DEFAULT -1,auth_version INTEGER DEFAULT 0);
    CREATE TABLE php7_admin_recovery_codes(id INTEGER PRIMARY KEY,account_id INTEGER,code_hash TEXT,used_at TEXT);
    CREATE TABLE php7_admin_users(id INTEGER PRIMARY KEY AUTOINCREMENT,account_id INTEGER UNIQUE,username TEXT UNIQUE,password_hash TEXT,role TEXT,is_active INTEGER DEFAULT 1,password_must_set INTEGER DEFAULT 0,password_setup_token_hash TEXT UNIQUE,password_setup_expires_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE php7_admin_roles(id INTEGER PRIMARY KEY,code TEXT UNIQUE,name TEXT,is_system INTEGER DEFAULT 0);
    CREATE TABLE php7_admin_role_permissions(role_id INTEGER,permission TEXT,PRIMARY KEY(role_id,permission));
    CREATE TABLE php7_admin_registry_state(id INTEGER PRIMARY KEY,version INTEGER DEFAULT 0);");
 }
 $hash=password_hash('FixtureOwner1!',PASSWORD_BCRYPT,array('cost'=>4));
 foreach(array(array(1,'Owner',4),array(2,'Mod',2),array(100,'GameOnly',1)) as $row)$pdo->prepare('INSERT INTO accounts(account_id,username,email,password_hash,gjp2_hash,role_id) VALUES(?,?,?,?,?,?)')->execute(array($row[0],$row[1],$row[1].'@example.invalid',$hash,$hash,$row[2]));
 $pdo->exec("INSERT INTO php7_admin_security(account_id,last_step,auth_version) VALUES(1,42,3),(99,50,2)");
 $_SESSION=array('csrf'=>str_repeat('a',48));$_FILES=array();$_SERVER['SCRIPT_NAME']='/mc/admin.php';
 $settings=array('site_url'=>'https://example.invalid','auth_pepper'=>str_repeat('p',64));
 mucho7_admin_registry_install($pdo);mucho7_admin_registry_install($pdo);
 arCheck((int)$pdo->query('SELECT COUNT(*) FROM php7_admin_users')->fetchColumn()===2,'legacy import repeated or added a non-admin');
 arCheck((int)mucho7_admin_user($pdo,1)['game_account_id']===1 && (int)mucho7_admin_security_row($pdo,1)['auth_version']===3,'legacy owner/factors changed');
 arCheck(mucho7_admin_password_login($pdo,array('userName'=>'Owner','password'=>'FixtureOwner1!'),'127.0.0.1',$settings)===1,'legacy owner login');
 arReject(function()use($pdo,$settings){mucho7_admin_password_login($pdo,array('userName'=>'GameOnly','password'=>'FixtureOwner1!'),'127.0.0.2',$settings);},'game user panel login');
 arReject(function()use($pdo,$settings){mucho7_admin_registry_action($pdo,2,'admin-create',array('username'=>'Denied','role'=>'owner'),$settings);},'moderator escalation');
 mucho7_admin_registry_action($pdo,1,'role-create',array('code'=>'curator','name'=>'Level curator','permissions'=>array('dashboard.view','levels.view','levels.rate','self.security')),$settings);
 $roleId=(int)$pdo->query("SELECT id FROM php7_admin_roles WHERE code='curator'")->fetchColumn();
 mucho7_admin_registry_action($pdo,1,'admin-create',array('username'=>'PanelOnly','role'=>'curator'),$settings);
 $id=(int)$pdo->query("SELECT id FROM php7_admin_users WHERE username='PanelOnly'")->fetchColumn();
 arCheck($id>100 && (int)$pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn()===3,'panel creation reused historical ID or created a game account');
 $invite=$_SESSION['admin_setup_invite'];parse_str((string)parse_url($invite['url'],PHP_URL_QUERY),$query);$token=$query['setup'];
 arCheck(strpos($invite['url'],'https://example.invalid/mc/admin.php?setup=')===0,'invitation mount');
 arCheck($pdo->query("SELECT password_setup_token_hash FROM php7_admin_users WHERE id=".$id)->fetchColumn()===hash('sha256',$token),'raw setup token stored');
 arCheck(mucho7_admin_user($pdo,$id)===null,'pending password can authenticate');
 mucho7_admin_setup_password($pdo,array('setup'=>$token,'password'=>'PanelPassword1!','password_confirm'=>'PanelPassword1!'),$settings);
 arReject(function()use($pdo,$token,$settings){mucho7_admin_setup_password($pdo,array('setup'=>$token,'password'=>'PanelPassword2!','password_confirm'=>'PanelPassword2!'),$settings);},'setup replay');
 arCheck(mucho7_admin_password_login($pdo,array('userName'=>'PanelOnly','password'=>'PanelPassword1!'),'127.0.0.3',$settings)===$id,'panel-only password login');
 $user=mucho7_admin_user($pdo,$id);
 arCheck($user['game_account_id']===null && !isset($user['password_hash']),'game identity leak or hash exposed');
 arCheck(mucho7_admin_can($pdo,$user,'levels.rate')&&!mucho7_admin_can($pdo,$user,'players.manage'),'custom permissions');
 arReject(function()use($pdo,$id,$root,$settings){mucho7_admin_ui_action($pdo,$id,'profile-save',array('id'=>100),$root,$settings);},'custom unauthorized profile change');
 arReject(function()use($pdo,$id,$settings){mucho7_admin_registry_action($pdo,$id,'admin-role',array('id'=>$id,'role'=>'owner'),$settings);},'custom self escalation');
 arReject(function()use($pdo,$roleId,$settings){mucho7_admin_registry_action($pdo,1,'role-delete',array('id'=>$roleId),$settings);},'assigned role deletion');
 $pdo->prepare('INSERT INTO php7_admin_security(account_id) VALUES(?)')->execute(array($id));
 if($mysql)$pdo->exec("CREATE TRIGGER reject_registry_audit BEFORE INSERT ON php7_admin_audit FOR EACH ROW BEGIN IF NEW.action='admin-role' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture audit failure'; END IF; END");
 else $pdo->exec("CREATE TRIGGER reject_registry_audit BEFORE INSERT ON php7_admin_audit WHEN NEW.action='admin-role' BEGIN SELECT RAISE(ABORT,'fixture audit failure'); END");
 try{
    arReject(function()use($pdo,$id,$settings){mucho7_admin_registry_action($pdo,1,'admin-role',array('id'=>$id,'role'=>'viewer'),$settings);},'audit failure must reject role assignment');
    arCheck(mucho7_admin_user($pdo,$id)['role']==='curator' && (int)mucho7_admin_security_row($pdo,$id)['auth_version']===0,'failed audit partially committed role/revision');
 }finally{$pdo->exec('DROP TRIGGER reject_registry_audit');}
 mucho7_admin_registry_action($pdo,1,'admin-role',array('id'=>$id,'role'=>'viewer'),$settings);
 arCheck((int)mucho7_admin_security_row($pdo,$id)['auth_version']===1,'role change retained old sessions');
 arCheck(!mucho7_admin_can($pdo,mucho7_admin_user($pdo,$id),'levels.rate'),'stale role permission cache');
 mucho7_admin_registry_action($pdo,1,'role-update',array('id'=>$roleId,'name'=>'Read only','permissions'=>array('levels.view')),$settings);
 arCheck(!in_array('levels.rate',\MuchoCore\Admin\AdminRbac::rolePermissions($pdo,'curator'),true),'role update cache');
 mucho7_admin_registry_action($pdo,1,'role-delete',array('id'=>$roleId),$settings);
 mucho7_admin_registry_action($pdo,1,'admin-toggle',array('id'=>$id),$settings);
 arCheck(mucho7_admin_user($pdo,$id)===null && (int)mucho7_admin_security_row($pdo,$id)['auth_version']===2,'disabled principal/session revocation');
 arReject(function()use($pdo,$id,$settings){mucho7_admin_password_login($pdo,array('userName'=>'PanelOnly','password'=>'PanelPassword1!'),'127.0.0.4',$settings);},'disabled panel login');
 arReject(function()use($pdo,$settings){mucho7_admin_registry_action($pdo,1,'admin-toggle',array('id'=>1),$settings);},'own owner disable');
 arCheck(mucho7_admin_has_owner($pdo),'lost active owner');
 mucho7_admin_registry_action($pdo,1,'admin-create',array('username'=>'ExpiredInvite','role'=>'viewer'),$settings);
 parse_str((string)parse_url($_SESSION['admin_setup_invite']['url'],PHP_URL_QUERY),$expiredQuery);
 $pdo->exec("UPDATE php7_admin_users SET password_setup_expires_at='2001-01-01 00:00:00' WHERE username='ExpiredInvite'");
 arCheck(mucho7_admin_setup_row($pdo,$expiredQuery['setup'])===null,'expired invitation accepted');
 $rows=$pdo->query(mucho7_admin_audit_rows_sql($pdo))->fetchAll(PDO::FETCH_ASSOC);
 arCheck(in_array('PanelOnly',array_column($rows,'username'),true),'panel audit identity was lost');
 $user=mucho7_admin_user($pdo,1);$schemaReady=true;$hasOwner=true;$freshInstall=false;$error='';$notice='';$adminNonce='fixtureNonce123';
 foreach(array('admins','roles') as $page){
    $_GET=array('page'=>$page);ob_start();require $root.'/compat/php7/admin-view.php';$html=ob_get_clean();
    arCheck(strpos($html,$page==='admins'?'Add administrator':'Create custom role')!==false,'original management markup absent');
    arCheck(!preg_match('~<[^>]+\\sonclick=~',$html),'CSP inline management handler');
    arCheck(strpos($html,'mcui=')!==false,'changed admin assets have no cache revision');
    if($page==='admins'&&class_exists('DOMDocument')){
        $document=new DOMDocument();$previous=libxml_use_internal_errors(true);$document->loadHTML($html,LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($previous);
        arCheck((new DOMXPath($document))->query('//main//table')->length===1,'administrator table escaped the main layout');
    }
 }
 if($mysql){
    require_once $root.'/compat/php7/admin-backups.php';
    $pdo->exec('CREATE TABLE backup_binary_fixture(id INT PRIMARY KEY,payload LONGBLOB)');
    $bytes="\0\xff\x80\n'\\".str_repeat('blob-data',10000);
    $pdo->prepare('INSERT INTO backup_binary_fixture VALUES(1,?)')->execute(array($bytes));
    $pdo->exec('CREATE VIEW backup_view_fixture AS SELECT id FROM backup_binary_fixture');
    $originalRoot=$root;$backupStage=sys_get_temp_dir().'/mucho7-backup-'.bin2hex(random_bytes(8));mkdir($backupStage.'/config',0700,true);$root=$backupStage;
    try{
        mucho7_admin_backup_action($pdo,1,'dbbackup-create',array());
        $files=glob(muchoDbBackupDir().'/*.sql.gz');arCheck(count($files)===1,'stored compressed backup absent');
        $path=mucho7_admin_backup_file(basename($files[0]));$sql=gzdecode(file_get_contents($path));
        arCheck(is_string($sql)&&strpos($sql,'php7_admin_users')!==false&&strpos($sql,'backup_view_fixture')!==false,'backup omitted identity tables or views');
        $restoreName=$database.'_restore';$server->exec('CREATE DATABASE '.$restoreName.' CHARACTER SET utf8mb4');
        try{
            $restore=new PDO($dsn.';dbname='.$restoreName,'root',$password,$attrs);$restore->exec($sql);
            arCheck($restore->query('SELECT payload FROM backup_binary_fixture WHERE id=1')->fetchColumn()===$bytes,'backup restore changed binary bytes');
            arCheck((int)$restore->query('SELECT COUNT(*) FROM php7_admin_users')->fetchColumn()===4,'backup restore omitted administrators');
            arCheck((int)$restore->query('SELECT COUNT(*) FROM backup_view_fixture')->fetchColumn()===1,'backup restore view');
        }finally{$server->exec('DROP DATABASE IF EXISTS '.$restoreName);}
        arReject(function()use($pdo,$id){mucho7_admin_backup_action($pdo,$id,'dbbackup-create',array());},'disabled user created backup');
        arReject(function(){mucho7_admin_backup_file('../outside.sql.gz');},'backup traversal');
        file_put_contents($path.'.sha256',str_repeat('0',64).'  '.basename($path));
        arReject(function()use($path){mucho7_admin_backup_file(basename($path));},'corrupt checksum download');
        echo "NATIVE_STORED_BACKUP_GZIP_CHECKSUM_BINARY_RESTORE_OK MYSQL\n";
    }finally{
        foreach(glob($backupStage.'/config/php7-db-backups/*') as $file)unlink($file);
        if(is_file($backupStage.'/config/php7-db-backups/.backup.lock'))unlink($backupStage.'/config/php7-db-backups/.backup.lock');
        if(is_dir($backupStage.'/config/php7-db-backups'))rmdir($backupStage.'/config/php7-db-backups');rmdir($backupStage.'/config');rmdir($backupStage);$root=$originalRoot;
    }
 }
 echo 'NATIVE_ADMIN_IDENTITIES_INVITES_RBAC_REVOCATION_OK '.($mysql?'MYSQL':'SQLITE')."\n";
}finally{if($server)$server->exec('DROP DATABASE IF EXISTS '.$database);}
