<?php
/** Behavioral parity for original News Board and administrator factors. */
declare(strict_types=1);
$root=dirname(__DIR__,2);
foreach(array('bootstrap','runtime','schema','auth','news','admin') as $module)require_once $root.'/compat/php7/'.$module.'.php';
require_once $root.'/compat/php7/admin-ui/bridge.php';
require_once $root.'/compat/php7/admin-ui/actions.php';
use MuchoCore\News\NewsService;
use MuchoCore\News\NewsMediaService;
use MuchoCore\News\NewsImage;
use MuchoCore\News\YouTubeVideo;
function nsCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
function nsReject($call,$message){try{$call();}catch(RuntimeException $error){return;}throw new RuntimeException('Accepted: '.$message);}
class NewsSecurityPDO extends PDO {
    #[\ReturnTypeWillChange]
    public function prepare($sql,$options=array()){return parent::prepare(str_replace(array(' FOR UPDATE','INSERT IGNORE'),array('','INSERT OR IGNORE'),$sql),$options);}
    #[\ReturnTypeWillChange]
    public function query($sql,...$args){return parent::query(str_replace(' FOR UPDATE','',$sql),...$args);}
    #[\ReturnTypeWillChange]
    public function exec($sql){
        if(strpos($sql,'ENGINE=InnoDB')!==false)return 0; // Explicit SQLite fixtures below; real DDL runs in --mysql CI.
        return parent::exec(str_replace('INSERT IGNORE','INSERT OR IGNORE',$sql));
    }
}
$mysql=in_array('--mysql',$argv,true);$server=null;$database='mucho_php7_news_security_ci';
$stage=sys_get_temp_dir().'/mucho7-news-security-'.bin2hex(random_bytes(8));mkdir($stage.'/config',0700,true);
try {
 if($mysql){
    $password=getenv('TEST_DB_ROOT_PASSWORD');if($password===false)throw new RuntimeException('TEST_DB_ROOT_PASSWORD required');
    $dsn='mysql:host='.(getenv('TEST_DB_HOST')?:'127.0.0.1').';port='.(getenv('TEST_DB_PORT')?:3306).';charset=utf8mb4';
    $attributes=array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC);
    $server=new PDO($dsn,'root',$password,$attributes);$server->exec('DROP DATABASE IF EXISTS '.$database);$server->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4');
    $pdo=new PDO($dsn.';dbname='.$database,'root',$password,$attributes);mucho7_create_schema($pdo);mucho7_upgrade_features($pdo);
 }else{
    $pdo=new NewsSecurityPDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('UTC_TIMESTAMP',function(){return gmdate('Y-m-d H:i:s');});
    $pdo->sqliteCreateFunction('IF',function($condition,$yes,$no){return $condition?$yes:$no;});
    $pdo->exec("CREATE TABLE roles(id INTEGER PRIMARY KEY,code TEXT);INSERT INTO roles VALUES(1,'user'),(2,'moderator'),(4,'owner');
    CREATE TABLE accounts(account_id INTEGER PRIMARY KEY,username TEXT,email TEXT,password_hash TEXT,gjp2_hash TEXT,role_id INTEGER,is_active INTEGER DEFAULT 1,is_banned INTEGER DEFAULT 0);
    CREATE TABLE php7_auth_events(id INTEGER PRIMARY KEY,action TEXT,ip_hash TEXT,username_hash TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE php7_admin_audit(id INTEGER PRIMARY KEY,account_id INTEGER,action TEXT,target_id INTEGER,details TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE php7_admin_security(account_id INTEGER PRIMARY KEY,totp_secret TEXT,last_step INTEGER DEFAULT -1,auth_version INTEGER DEFAULT 0);
    CREATE TABLE php7_admin_recovery_codes(id INTEGER PRIMARY KEY,account_id INTEGER,code_hash TEXT,used_at TEXT);
    CREATE TABLE mucho_news_posts(id INTEGER PRIMARY KEY,title TEXT,body TEXT,external_url TEXT,is_published INTEGER DEFAULT 0,is_pinned INTEGER DEFAULT 0,author_admin_id INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,published_at TEXT);
    CREATE TABLE mucho_news_state(id INTEGER PRIMARY KEY,version INTEGER);
    CREATE TABLE mucho_news_media(id INTEGER PRIMARY KEY,post_id INTEGER,kind TEXT,filename TEXT,width INTEGER,height INTEGER,target_url TEXT,image_data BLOB,FOREIGN KEY(post_id) REFERENCES mucho_news_posts(id) ON DELETE CASCADE);
    PRAGMA foreign_keys=ON;");
 }
 $hash=password_hash('FixturePassword1!',PASSWORD_BCRYPT,array('cost'=>4));
 $pdo->prepare('INSERT INTO accounts(account_id,username,email,password_hash,gjp2_hash,role_id) VALUES(?,?,?,?,?,?)')->execute(array(1,'Owner','owner@example.invalid',$hash,$hash,4));
 $pdo->prepare('INSERT INTO accounts(account_id,username,email,password_hash,gjp2_hash,role_id) VALUES(?,?,?,?,?,?)')->execute(array(2,'Mod','mod@example.invalid',$hash,$hash,2));
 $_SESSION=array('csrf'=>str_repeat('a',48));$_FILES=array();
 $legacy=array('version'=>1000123,'posts'=>array(array('id'=>7,'title'=>'Legacy','body'=>'Kept text','url'=>'https://example.invalid','published_at'=>'2026-09-13 12:00:00')));
 file_put_contents($stage.'/config/php7-news.json',json_encode($legacy));
 mucho7_news_install($pdo,$stage);mucho7_news_install($pdo,$stage);
 $news=new NewsService($pdo);nsCheck(count($news->published())===1 && $news->nativeVersion()==='1000124','legacy import is idempotent and keeps revision');
 nsCheck(json_decode(file_get_contents($stage.'/config/php7-news.json'),true)===$legacy,'legacy backup was altered');
 $version=$news->nativeVersion();$draftId=(new NewsService($pdo,$version))->save(0,'<script>draft</script>','Private text','',false,true,1);
 nsCheck(count($news->published())===1,'draft leaked into native feed');
 nsReject(function()use($pdo,$version){(new NewsService($pdo,$version))->save(0,'Stale','Should roll back','',true,false,1);},'stale editor');
 nsCheck(count($news->allForOwner())===2,'stale save partially persisted');
 $filename='mcnews_'.str_repeat('a',64).'.jpg';$testMediaService=new NewsMediaService($pdo);
 $pdo->prepare('INSERT INTO mucho_news_media(post_id,kind,filename,width,height,image_data) VALUES(?,?,?,?,?,?)')->execute(array($draftId,'image',$filename,800,454,'fixture JPEG bytes'));
 nsCheck($testMediaService->image($filename)===null && $testMediaService->image($filename,true)==='fixture JPEG bytes','draft image privacy');
 nsCheck($testMediaService->image('../'.$filename,true)===null,'image path traversal');
 $news->togglePublished($draftId);$published=$news->published();
 nsCheck($published[0]['id']===$draftId && count($published[0]['media'])===1,'pin or published media ordering');
 nsCheck(strpos(mucho7_news_payload($published),'1,'.$filename.',800,454,0,')!==false,'native image field order');
 $video=array('id'=>8,'title'=>'Test','body'=>'','published_at'=>'2026-09-13 00:00:00','media'=>array(array('kind'=>'youtube','filename'=>$filename,'width'=>800,'height'=>454,'target_url'=>'https://youtu.be/NgHLQeYr-qk')));
 $expected='4,1|1:8;2:U2VwdGVtYmVyIDEzLCAyMDI2;3:VGVzdA==;4:1,'.$filename.',800,454,1,aHR0cHM6Ly93d3cueW91dHViZS5jb20vd2F0Y2g_dj1OZ0hMUWVZci1xaw==';
 nsCheck(mucho7_news_payload(array($video))===$expected,'original GD 2.209 YouTube card parity');
 foreach(array('https://youtube.com.evil.test/watch?v=NgHLQeYr-qk','https://user:pass@youtube.com/watch?v=NgHLQeYr-qk','https://youtube.com/watch?v[]=x','http://youtu.be/NgHLQeYr-qk') as $url)
    nsReject(function()use($url){YouTubeVideo::canonicalUrl($url);},'unsafe video URL');
 nsReject(function(){NewsImage::fromBytes('<script>fake image</script>');},'invalid image');
 if(function_exists('imagecreatetruecolor')){
    $image=imagecreatetruecolor(2000,1000);ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
    $normalized=NewsImage::fromBytes($bytes);
    nsCheck($normalized->width===1600 && $normalized->height===800 && substr($normalized->bytes(),0,2)==="\xff\xd8",'bounded JPEG normalization');
    $version=$news->nativeVersion();
    $news->save($draftId,'Media only','','',true,true,1,array('images'=>array($normalized)));
    nsReject(function()use($news,$draftId,$testMediaService){$ids=array_column($testMediaService->forOwner($draftId),'id');$news->save($draftId,'Empty','','',true,true,1,array('remove'=>$ids));},'empty media-only post');
    nsCheck(count($testMediaService->forOwner($draftId))===2,'invalid media update was not rolled back');
    echo "NATIVE_NEWS_GD_NORMALIZATION_OK\n";
 }else echo "NATIVE_NEWS_GD_NORMALIZATION_SKIPPED (GD unavailable)\n";
 $settings=array('site_url'=>'https://example.invalid','auth_pepper'=>str_repeat('p',64));
 nsReject(function()use($pdo,$stage,$settings){mucho7_admin_ui_action($pdo,2,'news-delete',array('id'=>7),$stage,$settings);},'moderator news action');
 $user=array('account_id'=>1,'username'=>'Owner','role'=>'owner');$_SERVER['SCRIPT_NAME']='/mc/admin.php';$adminNonce='testNonce123';
 $hasOwner=true;$schemaReady=true;$freshInstall=false;$error='';$notice='';$page='news';$_GET=array('edit'=>$draftId);
 ob_start();require $root.'/compat/php7/admin-view.php';$html=ob_get_clean();
 nsCheck(strpos($html,'mucho-news-layout')!==false && strpos($html,'news_version')!==false && strpos($html,'/mc/admin.php?news_image=')!==false,'original News editor/mount');
 nsCheck(!preg_match('~<[^>]+(?:\\sstyle=|\\sonsubmit=)~',$html),'News CSP handlers/styles');
 $news->delete($draftId);nsCheck($testMediaService->image($filename,true)===null,'deleted news retained public media');
 echo "NATIVE_NEWS_IMPORT_DRAFT_PIN_REVISION_MEDIA_RBAC_OK\n";

 nsCheck(totp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',0,59)==='287082','RFC6238 vector');
 nsCheck(mucho7_admin_totp_step('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ','287082',59)===1,'TOTP time window');
 nsCheck(mucho7_admin_totp_step('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ','000000',59)===false,'wrong TOTP accepted');
 nsReject(function()use($pdo,$settings){mucho7_admin_security_action($pdo,1,'2fa-generate',array('current_password'=>'wrong'),$settings);},'factor setup without password');
 mucho7_admin_security_action($pdo,1,'2fa-generate',array('current_password'=>'FixturePassword1!'),$settings);
 $secret=$_SESSION['pending_totp']['secret'];
 $page='admins';$_GET=array();ob_start();require $root.'/compat/php7/admin-view.php';$html=ob_get_clean();
 nsCheck(strpos($html,'Google Authenticator')!==false && strpos($html,'otpauth')!==false && strpos(str_replace('\\/','/',$html),'/mc/public/admin/assets/qrcode.min.js')!==false,'original authenticator/QR mount');
 mucho7_admin_security_action($pdo,1,'2fa-enable',array('otp'=>totp($secret)),$settings);
 nsCheck((int)$_SESSION['auth_version']===1 && count($_SESSION['new_recovery_codes'])===10,'factor activation/session revision/recovery');
 $codes=$_SESSION['new_recovery_codes'];
 nsReject(function()use($pdo,$secret){mucho7_admin_factor_login($pdo,1,totp($secret));},'replayed TOTP');
 nsReject(function()use($pdo){mucho7_admin_factor_login($pdo,1,'');},'missing second factor');
 nsCheck(mucho7_admin_factor_login($pdo,1,$codes[0])===1,'recovery sign-in');
 nsReject(function()use($pdo,$codes){mucho7_admin_factor_login($pdo,1,$codes[0]);},'replayed recovery');
 mucho7_admin_security_action($pdo,1,'recovery-generate',array('current_password'=>'FixturePassword1!','otp'=>$codes[1]),$settings);
 nsReject(function()use($pdo,$codes){mucho7_admin_factor_login($pdo,1,$codes[2]);},'revoked recovery');
 $newCodes=$_SESSION['new_recovery_codes'];
 nsReject(function()use($pdo,$settings,$newCodes){mucho7_admin_security_action($pdo,1,'2fa-disable',array('current_password'=>'wrong','otp'=>$newCodes[0]),$settings);},'disable without password');
 mucho7_admin_security_action($pdo,1,'2fa-disable',array('current_password'=>'FixturePassword1!','otp'=>$newCodes[0]),$settings);
 nsCheck(mucho7_admin_factor_login($pdo,1,'')===2 && (int)$_SESSION['auth_version']===2,'factor disable/session invalidation');
 nsCheck(countUnusedAdminRecoveryCodes($pdo,1)===0,'disable retained recovery codes');
 if($mysql){
    // Execute the real front controller in a separate PHP process, using only
    // this disposable database/session fixture. No production login is used.
    mkdir($stage.'/public',0700);mkdir($stage.'/sessions',0700);
    symlink($root.'/compat',$stage.'/compat');
    copy($root.'/public/php7-admin.php',$stage.'/public/php7-admin.php');
    $fixtureSettings=array('host'=>getenv('TEST_DB_HOST')?:'127.0.0.1','port'=>(int)(getenv('TEST_DB_PORT')?:3306),
        'database'=>$database,'username'=>'root','password'=>$password,'site_url'=>'https://example.invalid','auth_pepper'=>str_repeat('p',64));
    file_put_contents($stage.'/config/php7-local.php','<?php return '.var_export($fixtureSettings,true).';');
    $fixtureCsrf=bin2hex(random_bytes(24));
    $fixtureSession=array('aid'=>1,'signed_in'=>time(),'last_seen'=>time(),'auth_version'=>2,'csrf'=>$fixtureCsrf,
        'instance'=>hash_hmac('sha256',$stage.'|'.$fixtureSettings['host'].'|'.$database,$password));
    $fixturePost=array('csrf'=>$fixtureCsrf,'action'=>'news-save','id'=>'0','news_version'=>$news->nativeVersion(),
        'title'=>'Keep this editor draft','body'=>'Not published','youtube_url'=>'https://youtube.com.evil.invalid/watch?v=NgHLQeYr-qk');
    $wrapper="<?php\nini_set('session.save_path',__DIR__.'/sessions');session_name('muchocore7_admin_'.substr(hash('sha256',__DIR__),0,12));session_id('fixture".bin2hex(random_bytes(12))."');session_start();\n";
    $wrapper.='$_SESSION='.var_export($fixtureSession,true).";session_write_close();\n";
    $wrapper.="\$_SERVER['HTTPS']='on';\$_SERVER['SCRIPT_NAME']='/public/php7-admin.php';\$_SERVER['REQUEST_METHOD']='POST';\$_SERVER['REMOTE_ADDR']='127.0.0.1';\$_GET=array('page'=>'news');\$_FILES=array();\n";
    $wrapper.='$_POST='.var_export($fixturePost,true).";require __DIR__.'/public/php7-admin.php';\n";
    file_put_contents($stage.'/request.php',$wrapper);
    $process=proc_open(escapeshellarg(PHP_BINARY).' '.escapeshellarg($stage.'/request.php'),array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
    if(!is_resource($process))throw new RuntimeException('Unable to launch controller fixture');
    $response=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    nsCheck($exit===0,'Front controller fixture failed: '.$stderr);
    preg_match('~<(?:p|div)[^>]*class="[^"]*(?:err|flash)[^"]*"[^>]*>(.*?)</(?:p|div)>~s',$response,$failureMessage);
    nsCheck(strpos($response,'Use a YouTube watch, Shorts, live or youtu.be video link.')!==false,'missing form validation error: '.(isset($failureMessage[1])?strip_tags($failureMessage[1]):substr(strip_tags($response),-900)).' stderr: '.$stderr);
    nsCheck(strpos($response,'mucho-news-layout')!==false && strpos($response,'Keep this editor draft')!==false,'validation lost original editor/draft');
    nsCheck(strpos($response,'Доступно обновление базы')===false,'validation incorrectly offered a schema upgrade');
    nsCheck(count($news->allForOwner())===1,'rejected front-controller POST created news');
    echo "NATIVE_ADMIN_REJECTED_POST_PRESERVES_EDITOR_OK\n";
 }
 echo 'NATIVE_TOTP_RECOVERY_REPLAY_REAUTH_SESSION_OK '.($mysql?'MYSQL':'SQLITE')."\n";
}finally{
 if($server)$server->exec('DROP DATABASE IF EXISTS '.$database);
 foreach(glob($stage.'/config/*') as $file)unlink($file);
 foreach(array('public','sessions') as $directory){if(is_dir($stage.'/'.$directory)){foreach(glob($stage.'/'.$directory.'/*') as $file)unlink($file);rmdir($stage.'/'.$directory);}}
 if(is_link($stage.'/compat'))unlink($stage.'/compat');
 if(is_file($stage.'/request.php'))unlink($stage.'/request.php');
 rmdir($stage.'/config');rmdir($stage);
}
