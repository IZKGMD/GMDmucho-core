<?php
$root=dirname(__DIR__,2);
foreach(array('bootstrap','runtime','schema','news','admin') as $module)require_once $root.'/compat/php7/'.$module.'.php';
class AdminUiTestPDO extends PDO {
    #[\ReturnTypeWillChange]
    public function prepare($query,$options=array()){return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);}
    #[\ReturnTypeWillChange]
    public function query($query,...$args){if($query==='SHOW TABLES')$query="SELECT name FROM sqlite_master WHERE type='table' ORDER BY name";return parent::query(str_replace(' FOR UPDATE','',$query),...$args);}
}
$pdo=new AdminUiTestPDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE roles(id INTEGER PRIMARY KEY,code TEXT,priority INTEGER); INSERT INTO roles VALUES(4,'owner',30);
CREATE TABLE accounts(account_id INTEGER PRIMARY KEY,username TEXT,email TEXT,role_id INTEGER,is_active INTEGER,is_banned INTEGER,created_at TEXT); INSERT INTO accounts VALUES(1,'Owner','owner@example.invalid',4,1,0,'2026-10-23');
CREATE TABLE profiles(account_id INTEGER,stars INTEGER,moons INTEGER,diamonds INTEGER,secret_coins INTEGER,user_coins INTEGER,demons INTEGER,creator_points INTEGER); INSERT INTO profiles VALUES(1,100,20,50,3,9,2,10);
CREATE TABLE levels(level_id INTEGER PRIMARY KEY,name TEXT,account_id INTEGER,stars INTEGER,difficulty INTEGER,demon INTEGER,demon_difficulty INTEGER,auto_level INTEGER,featured INTEGER,epic INTEGER,requested_stars INTEGER,downloads INTEGER,likes INTEGER,is_deleted INTEGER); INSERT INTO levels VALUES(1,'<script>alert(1)</script>',1,7,40,0,0,0,1,1,8,50,12,0);
CREATE TABLE php7_comments(id INTEGER PRIMARY KEY,kind INTEGER,content TEXT); INSERT INTO php7_comments VALUES(1,1,'Level text'),(2,0,'Profile text');
CREATE TABLE php7_messages(id INTEGER PRIMARY KEY,account_id INTEGER,to_account_id INTEGER,subject TEXT,body TEXT,is_read INTEGER);
CREATE TABLE php7_admin_audit(id INTEGER PRIMARY KEY,account_id INTEGER,action TEXT,target_id INTEGER,details TEXT,created_at TEXT);
CREATE TABLE songs(id INTEGER,name TEXT,author_name TEXT,size REAL,is_verified INTEGER,created_at TEXT); INSERT INTO songs VALUES(1,'Test Song','Test Artist',2.5,1,'2026-10-23');");
$_SESSION=array('csrf'=>str_repeat('a',48));$_SERVER['SCRIPT_NAME']='/mc/admin.php';$adminNonce='testNonce123';
$settings=array('site_url'=>'https://example.invalid');$hasOwner=true;$schemaReady=true;$freshInstall=false;$error='';$notice='';
$user=array('account_id'=>1,'username'=>'Owner','role'=>'owner');
foreach(array('dashboard','players','levels','rating','songs','comments','messages','audit','settings','clientpatcher') as $page){
 $_GET=array('page'=>$page,'id'=>1);ob_start();require $root.'/compat/php7/admin-view.php';$html=ob_get_clean();
 if(strpos($html,'GDPS Administration')===false||strpos($html,'/mc/public/admin/admin-simple.css')===false||strpos($html,'/mc/public/admin/assets/gd/')===false)throw new RuntimeException('Original shell/assets absent: '.$page);
 if(preg_match('~<[^>]+\sstyle=~',$html))throw new RuntimeException('Unnonced layout attribute: '.$page);
 foreach(array('admin-simple.js','php7-admin-product.js') as $asset){if(substr_count($html,$asset)!==1)throw new RuntimeException('Duplicate or missing behavior script: '.$page.' '.$asset);}
 if(substr_count($html,'admin-workspace.css')!==1)throw new RuntimeException('Missing workspace composition: '.$page);
 if($page==='clientpatcher'){
  $dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);
  foreach(array('sharedPatcherFile','sharedPatcherServer','sharedPatcherAlongside','sharedPatcherStart','sharedPatcherReset','sharedPatcherProgress','sharedPatcherStatus','sharedPatcherDownload','sharedPatcherReport') as $id)if($xp->query('//*[@id="sharedPatcher"]//*[@id="'.$id.'"]')->length!==1)throw new RuntimeException('Patcher control moved outside its form: '.$id);
 }
 if($page==='players'&&strpos($html,'admin-record')===false)throw new RuntimeException('Original player record absent');
 if($page==='levels'&&strpos($html,'&lt;script&gt;alert(1)&lt;/script&gt;')===false)throw new RuntimeException('Level XSS escaping failed');
 if($page==='rating'&&strpos($html,'difficultyFacePreview')===false)throw new RuntimeException('Original rating editor absent');

 echo 'ORIGINAL_ADMIN_RENDER_OK '.$page."\n";
}
// Render the newly composed tools independently of the compiled core bootstrap.
$page='endpoints';$_SESSION['endpoint_result']='<script>response</script>';ob_start();require $root.'/compat/php7/admin-ui/endpoints.php';$endpointHtml=ob_get_clean();
if(isset($_SESSION['endpoint_result'])||strpos($endpointHtml,'&lt;script&gt;response&lt;/script&gt;')===false)throw new RuntimeException('Endpoint response escaping/consumption changed');
$dom=new DOMDocument();@$dom->loadHTML($endpointHtml);$xp=new DOMXPath($dom);
if($xp->query('//form[input[@name="csrf"] and input[@name="action" and @value="endpoint-test"]]//textarea[@name="payload"]')->length!==1)throw new RuntimeException('Endpoint request controls lost their form');
$page='database';$_GET=array('table'=>'profiles','table_q'=>'PROF');ob_start();require $root.'/compat/php7/admin-ui/database.php';$databaseHtml=ob_get_clean();
$dom=new DOMDocument();@$dom->loadHTML($databaseHtml);$xp=new DOMXPath($dom);
if($xp->query('//nav[@aria-label="Table selection"]/a')->length!==1||$xp->query('//a[@aria-current="page"]')->length!==1||strpos($databaseHtml,'<td>100</td>')===false)throw new RuntimeException('Database filter or selected table preview failed');
$_GET=array('table'=>array('accounts'),'table_q'=>array('accounts'));ob_start();require $root.'/compat/php7/admin-ui/database.php';$databaseHtml=ob_get_clean();
if(strpos($databaseHtml,'Select a table')===false)throw new RuntimeException('Database invalid filter handling failed');
echo "ORIGINAL_ADMIN_TOOLS_RENDER_OK\n";
$page='players';$_GET=array('ui'=>'plain','q'=>'Owner');ob_start();require $root.'/compat/php7/admin-view.php';$plain=ob_get_clean();if(substr_count(strtolower($plain),'<!doctype')!==1||strpos($plain,'rel="stylesheet"')!==false)throw new RuntimeException('Plain document regression');
foreach(array('admin-simple.js','php7-admin-product.js') as $asset){if(substr_count($plain,$asset)!==1)throw new RuntimeException('Duplicate or missing Plain behavior script: '.$asset);}
preg_match_all('~<a\\b[^>]*>~',$plain,$links);
foreach($links[0] as $link){
 if(strpos($link,'data-styled-admin')!==false){if(strpos($link,'ui=plain')!==false)throw new RuntimeException('Styled switch retained Plain mode');continue;}
 if(strpos($link,'/mc/admin.php?')!==false && strpos($link,'ui=plain')===false)throw new RuntimeException('Plain navigation lost mode: '.$link);
}
if(strpos($plain,'page=players&amp;ui=plain')===false)throw new RuntimeException('Plain reset link absent');
if(!preg_match('~<body[^>]+data-csrf="[^"]+"[^>]+data-admin-path="/mc/admin.php"~',$plain))throw new RuntimeException('Plain logout is missing mounted CSRF context');
echo "ORIGINAL_PLAIN_RENDER_AND_NAVIGATION_OK\n";
$user=null;$page='dashboard';$_GET=array();ob_start();require $root.'/compat/php7/admin-view.php';$login=ob_get_clean();if(strpos($login,'admin-auth-card')===false||strpos($login,'Administrator sign in')===false)throw new RuntimeException('Original login absent');echo "ORIGINAL_LOGIN_RENDER_OK\n";

require $root.'/compat/php7/admin-ui/actions.php';
$pdo->exec("ALTER TABLE accounts ADD password_hash TEXT; ALTER TABLE accounts ADD gjp2_hash TEXT; ALTER TABLE levels ADD coins_verified INTEGER DEFAULT 1; ALTER TABLE levels ADD updated_at TEXT;
INSERT INTO roles VALUES(1,'user',0),(2,'moderator',10); INSERT INTO accounts(account_id,username,email,role_id,is_active,is_banned,created_at)VALUES(2,'Peer','peer@example.invalid',1,1,0,'2026-10-23'),(3,'Mod','mod@example.invalid',2,1,0,'2026-10-23');
INSERT INTO profiles VALUES(2,10,0,0,0,0,0,0);");
$bad=array('id'=>'1','username'=>'Owner','email'=>'owner@example.invalid','role'=>'owner');
try {mucho7_admin_ui_action($pdo,1,'account-save',$bad,$root,$settings);throw new LogicException('Self deactivation accepted');}catch(RuntimeException $e){}
if((int)$pdo->query('SELECT is_active FROM accounts WHERE account_id=1')->fetchColumn()!==1)throw new RuntimeException('Self owner lockout');
$bad=array('id'=>'2','username'=>'<invalid>','email'=>'peer@example.invalid','role'=>'owner','active'=>'on');
try {mucho7_admin_ui_action($pdo,1,'account-save',$bad,$root,$settings);throw new LogicException('Invalid name accepted');}catch(RuntimeException $e){}
if((int)$pdo->query('SELECT role_id FROM accounts WHERE account_id=2')->fetchColumn()!==1)throw new RuntimeException('Failed account save partially changed role');
$data=array('id'=>'2','stars'=>'400','moons'=>'12','diamonds'=>'20','secret_coins'=>'3','user_coins'=>'4','demons'=>'2','creator_points'=>'5');
mucho7_admin_ui_action($pdo,1,'profile-save',$data,$root,$settings);
if((int)$pdo->query('SELECT stars FROM profiles WHERE account_id=2')->fetchColumn()!==400)throw new RuntimeException('Profile form did not persist');
try {mucho7_admin_ui_action($pdo,3,'profile-save',array('id'=>'2','stars'=>'999'),$root,$settings);throw new LogicException('Moderator wrote profile');}catch(RuntimeException $e){}
if((int)$pdo->query('SELECT stars FROM profiles WHERE account_id=2')->fetchColumn()!==400)throw new RuntimeException('Unauthorized profile mutation');
mucho7_admin_ui_action($pdo,1,'password-reset',array('id'=>'2','new_password'=>'TestOnlyPassword1!','new_password_confirm'=>'TestOnlyPassword1!'),$root,$settings);
$hashes=$pdo->query('SELECT password_hash,gjp2_hash FROM accounts WHERE account_id=2')->fetch(PDO::FETCH_ASSOC);
if(!password_verify('TestOnlyPassword1!',$hashes['password_hash'])||!password_verify(sha1('TestOnlyPassword1!mI29fmAnxgTs'),$hashes['gjp2_hash']))throw new RuntimeException('Password reset desynchronized game credentials');
echo "ORIGINAL_ADMIN_FORM_RBAC_ROLLBACK_PASSWORD_OK\n";

mucho7_admin_ui_action($pdo,1,'level-rate-save',array('id'=>'1','stars'=>'8','difficulty_profile'=>'harder','feature'=>'3'),$root,$settings);
$level=$pdo->query('SELECT stars,difficulty,featured,epic,coins_verified,requested_stars FROM levels WHERE level_id=1')->fetch(PDO::FETCH_ASSOC);
if((int)$level['stars']!==8||(int)$level['difficulty']!==40||(int)$level['epic']!==2||(int)$level['coins_verified']!==1)throw new RuntimeException('Original Rating Studio adapter damaged rating/coins');
if((int)$level['requested_stars']!==0)throw new RuntimeException('Published rating remained in the moderation queue');
foreach(json_decode(file_get_contents($root.'/compat/php7/admin-ui/upstream-sources.json'),true) as $file=>$hash){if(!hash_equals($hash,hash_file('sha256',$root.'/'.$file)))throw new RuntimeException('Original admin source drift: '.$file);}
echo "ORIGINAL_RATING_AND_SOURCE_PROVENANCE_OK\n";
