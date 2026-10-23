<?php
/** Browser administration for the native shared runtime. PHP 7.0+. */
declare(strict_types=1);
// Keep incidental output from host configuration/includes away from headers.
// Downloads and machine-readable responses discard it at their response boundary.
ob_start();
function mucho7_admin_clean_response()
{
    while (ob_get_level()>0) {
        if (!ob_end_clean()) { throw new RuntimeException('Unable to prepare a clean response.'); }
    }
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
$adminNonce=base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'nonce-".$adminNonce."'; script-src 'self' 'nonce-".$adminNonce."'; connect-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$root=dirname(__DIR__);
require_once $root.'/compat/php7/bootstrap.php';
if(!mucho7_https()) {http_response_code(403);echo 'Открой панель по HTTPS.';exit;}
foreach(array('runtime','schema','auth','news','admin') as $module){require_once $root.'/compat/php7/'.$module.'.php';}
require_once $root.'/compat/php7/admin-ui/actions.php';
require_once $root.'/compat/php7/admin-ui/bridge.php';
session_name('muchocore7_admin_'.substr(hash('sha256',$root),0,12));
ini_set('session.cookie_httponly','1');ini_set('session.cookie_secure','1');
ini_set('session.use_strict_mode','1');ini_set('session.cookie_samesite','Strict');
session_start();
if(empty($_SESSION['csrf'])){$_SESSION['csrf']=bin2hex(random_bytes(24));}
if(!empty($_SESSION['aid']) && (time()-(int)$_SESSION['last_seen']>1800 || time()-(int)$_SESSION['signed_in']>28800)) {
    unset($_SESSION['aid']);session_regenerate_id(true);
}
$error='';$notice=isset($_SESSION['notice'])?$_SESSION['notice']:'';unset($_SESSION['notice']);
$user=null;$pdo=null;$settings=array();$hasOwner=true;$schemaReady=false;$freshInstall=false;
$page=isset($_GET['page']) && is_string($_GET['page'])?$_GET['page']:'overview';
if($page==='diagnostics')$page='system';
if(!in_array($page,array('overview','dashboard','players','levels','rating','moderation','comments','messages','songs','news','settings','system','audit','dbbackups','admins','roles'),true)){$page='dashboard';}
$method=isset($_SERVER['REQUEST_METHOD'])?strtoupper((string)$_SERVER['REQUEST_METHOD']):'GET';
try {
    if(!is_file($root.'/config/php7-local.php')){throw new RuntimeException('Сначала настрой подключение в config/php7-local.php или выполни php7-setup.php.');}
    $settings=require $root.'/config/php7-local.php'; $pdo=mucho7_connect($settings);
    // Shared hosts may serve several installations from one PHP session store.
    // A valid session for another installation must never grant this one's ID.
    $instance=hash_hmac('sha256',$root.'|'.(string)$settings['host'].'|'.(string)$settings['database'],(string)$settings['password']);
    if(!empty($_SESSION['aid']) && (!isset($_SESSION['instance']) || !hash_equals($instance,(string)$_SESSION['instance']))) {
        unset($_SESSION['aid']);$_SESSION['csrf']=bin2hex(random_bytes(24));session_regenerate_id(true);
    }
    $freshInstall=count($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN))===0;
    if(!$freshInstall)mucho7_admin_registry_install($pdo);
    // Existing native sessions referred to game IDs. The first import retains
    // those IDs; record the explicit principal namespace before future use.
    if(!empty($_SESSION['aid']) && empty($_SESSION['admin_registry'])){
        $q=$pdo->prepare('SELECT id FROM php7_admin_users WHERE account_id=?');$q->execute(array((int)$_SESSION['aid']));
        $legacyId=(int)$q->fetchColumn();if($legacyId>0){$_SESSION['aid']=$legacyId;$_SESSION['admin_registry']=1;}else unset($_SESSION['aid']);
    }
    $hasOwner=$freshInstall?false:mucho7_admin_has_owner($pdo);
    if(!empty($_SESSION['aid'])){
        $candidate=mucho7_admin_user($pdo,(int)$_SESSION['aid']);
        if($candidate){
            $security=mucho7_admin_security_row($pdo,(int)$candidate['account_id']);
            if((int)$security['auth_version']===(isset($_SESSION['auth_version'])?(int)$_SESSION['auth_version']:0))$user=$candidate;
        }
        if(!$user){unset($_SESSION['aid']);session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(24));}
    }
    if($user){$_SESSION['last_seen']=time();}
    if($user && $page==='admins')mucho7_admin_security_install($pdo);
    if($user && $user['role']==='owner' && $page==='news')mucho7_news_install($pdo,$root);
    // A rejected form must render the same editor, not the upgrade fallback.
    if($user) {
        $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $schemaReady=count(array_diff(mucho7_admin_tables(),$tables))===0;
    }
    $setupToken=isset($_GET['setup'])&&is_string($_GET['setup'])?$_GET['setup']:(isset($_POST['setup'])&&is_string($_POST['setup'])?$_POST['setup']:'');
    $setupMode=$setupToken!=='' || (isset($_POST['action'])&&$_POST['action']==='admin-password-setup');
    $setupSuccess=false;
    if($method==='POST') {
        $postLimit=isset($_POST['action']) && $_POST['action']==='music-upload'?22*1024*1024:131072;
        if(isset($_POST['action']) && $_POST['action']==='news-save')$postLimit=37*1024*1024;
        if((isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH']>$postLimit)
            || !isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals($_SESSION['csrf'],$_POST['csrf'])) {
            http_response_code(403);throw new RuntimeException('Форма устарела. Обнови страницу и повтори действие.');
        }
        $action=isset($_POST['action']) && is_string($_POST['action'])?$_POST['action']:'';
        if($action==='admin-password-setup'){
            mucho7_admin_setup_password($pdo,$_POST,$settings);$setupSuccess=true;
        }else{
        if($action==='login' || ($action==='bootstrap' && !$hasOwner)) {
            $pepper=isset($settings['auth_pepper'])?$settings['auth_pepper']:'';
            $ip=isset($_SERVER['REMOTE_ADDR'])?(string)$_SERVER['REMOTE_ADDR']:'';
            if($action==='bootstrap') {
                mucho7_admin_bootstrap_limit($root,$ip,isset($settings['password'])?$settings['password']:'');
                $aid=mucho7_admin_initialize($pdo,$settings,$_POST,$root);
                mucho7_admin_registry_install($pdo);
                $q=$pdo->prepare('SELECT id FROM php7_admin_users WHERE account_id=?');$q->execute(array($aid));$aid=(int)$q->fetchColumn();
                if($aid<=0){
                    $q=$pdo->prepare("SELECT a.account_id,a.username FROM accounts a JOIN roles r ON r.id=a.role_id WHERE r.code='owner' AND a.username=?");$q->execute(array($_POST['userName']));$ownerRow=$q->fetch(PDO::FETCH_ASSOC);
                    $pdo->prepare("INSERT INTO php7_admin_users(account_id,username,role) VALUES(?,?,'owner')")->execute(array($ownerRow['account_id'],$ownerRow['username']));$aid=(int)$pdo->lastInsertId();
                }
            } else {
                $aid=mucho7_admin_password_login($pdo,$_POST,$ip,$settings);
            }
            $loginUser=$aid>0?mucho7_admin_user($pdo,$aid):null;
            if(!$loginUser){throw new RuntimeException('Проверь логин, пароль и роль администратора.');}
            $factorVersion=mucho7_admin_factor_login($pdo,$aid,isset($_POST['otp']) && is_string($_POST['otp'])?trim($_POST['otp']):'');
            $user=$loginUser;
            session_regenerate_id(true);$_SESSION=array();$_SESSION['aid']=$aid;$_SESSION['signed_in']=time();$_SESSION['last_seen']=time();
            $_SESSION['auth_version']=$factorVersion;
            $_SESSION['admin_registry']=1;
            $_SESSION['instance']=$instance;
            $_SESSION['csrf']=bin2hex(random_bytes(24));$_SESSION['notice']='Вход выполнен.';
            header('Set-Cookie: '.session_name().'='.rawurlencode(session_id()).'; Path=/; Secure; HttpOnly; SameSite=Strict',false);
            header('Location: ?page=overview',true,303);exit;
        }
        if(!$user){http_response_code(403);throw new RuntimeException('Сначала войди в панель.');}
        if($action==='logout') {
            $_SESSION=array();session_regenerate_id(true);header('Location: ?',true,303);exit;
        }
        if($action==='backup'){mucho7_admin_clean_response();mucho7_admin_backup($pdo,(int)$user['account_id']);exit;}
        if($action==='dbbackup-download'){
            require_once $root.'/compat/php7/admin-backups.php';
            mucho7_admin_clean_response();
            mucho7_admin_backup_action($pdo,(int)$user['account_id'],$action,$_POST);exit;
        }
        mucho7_admin_ui_action($pdo,(int)$user['account_id'],$action,$_POST,$root,$settings);
        $_SESSION['notice']=$action==='upgrade'?'База обновлена. Данные сохранены.':'Изменения сохранены.';
        $return=array('page'=>$page);
        foreach(array('q','filter','p') as $key){if(isset($_POST['return_'.$key]) && is_string($_POST['return_'.$key]))$return[$key]=substr($_POST['return_'.$key],0,128);}
        if($page==='rating' && isset($_POST['id']))$return['id']=(int)$_POST['id'];
        if(isset($_GET['ui']) && $_GET['ui']==='plain')$return['ui']='plain';
        header('Location: ?'.http_build_query($return),true,303);exit;
        }
    }
} catch(PDOException $e){
    $error='Не удалось выполнить запрос к базе. Проверь подключение и обнови схему базы в панели.';
    error_log('[MuchoCore Shared admin] Database operation failed');
} catch(Exception $e){$error=$e->getMessage();}
if(!empty($setupMode)){
    $script=isset($_SERVER['SCRIPT_NAME'])?(string)$_SERVER['SCRIPT_NAME']:'/mc/admin.php';$publicBase='';
    $row=$pdo&&!$setupSuccess?mucho7_admin_setup_row($pdo,$setupToken):null;
    $success=$setupSuccess;$token=$setupToken;
    echo mucho7_admin_ui_html((function()use($root,$row,$success,$token,$script,$error){ob_start();require $root.'/compat/php7/admin-ui/password-setup.php';return ob_get_clean();})());exit;
}
if(isset($_GET['news_image'])){
    if(!$user || $user['role']!=='owner'){http_response_code(403);exit;}
    mucho7_admin_clean_response();
    \MuchoCore\News\NewsImageResponse::send($pdo,is_string($_GET['news_image'])?$_GET['news_image']:'',true);
}
if(isset($_GET['news_feed'])){
    mucho7_admin_clean_response();
    header('Content-Type: application/json; charset=utf-8');
    if(!$pdo || !is_file($root.'/config/php7-news-sql.on')){http_response_code(503);echo '{"posts":[]}';exit;}
    try{echo json_encode(array('posts'=>(new \MuchoCore\News\NewsService($pdo))->published()));}
    catch(Exception $e){http_response_code(503);echo '{"posts":[]}';}
    exit;
}
if(isset($_GET['audit_feed'])){
    mucho7_admin_clean_response();
    header('Content-Type: application/json; charset=utf-8');
    if(!mucho7_admin_can($pdo,$user,'audit.view')){http_response_code(403);echo '{"ok":false}';exit;}
    $events=$pdo->query(mucho7_admin_audit_rows_sql($pdo).' LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(array('ok'=>true,'events'=>$events));exit;
}
require $root.'/compat/php7/admin-view.php';
