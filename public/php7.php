<?php
/**
 * Native PHP 7 shared runtime; no forwarding to a VPS.
 * Uses compiled original domains after the owner completes the data upgrade.
 */
declare(strict_types=1);
ob_start();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$root = dirname(__DIR__);
if (!is_file(__DIR__ . '/php7-mode.on')
    || !is_file($root . '/config/php7-local.php')) {
    http_response_code(503);
    echo '-1';
    exit;
}

$https = (isset($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] === 'on')
    || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443');
if (!$https) {
    http_response_code(403);
    echo '-1';
    exit;
}

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
require_once $root . '/compat/php7/runtime.php';
require_once $root . '/compat/php7/bootstrap.php';
require_once $root . '/compat/php7/core-update.php';
try {
    $runtimeLock=mucho7_core_lock($root,false);
    if(is_file($root.'/config/php7-core-transition.json'))throw new RuntimeException('Core transition in progress.');
} catch(Exception $e){http_response_code(503);header('Retry-After: 10');echo '-1';exit;}
$uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
$uri = mucho7_mount_uri($uri, isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '');
// URL-encoded binary saves can expand to three times their decoded size.
$requestLimit = preg_match('~^/(?:backupgjaccount|uploadgjlevel)(?:new|19|20|21|211|22)?$~D', mucho7_path($uri))
    ? 24*1024*1024+8192 : 262144;
if(preg_match('~^/(?:(?:api/)?profile/upload|uploadgjeditorsound)(?:19|20|21|211|22)?$~D',mucho7_path($uri)))$requestLimit=18*1024*1024;
if (($method !== 'POST' && $method !== 'GET' && $method !== 'HEAD')
    || (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > $requestLimit)) {
    http_response_code(413);
    echo '-1';
    exit;
}

require_once $root . '/compat/php7/runtime.php';
require_once $root . '/compat/php7/download.php';
require_once $root . '/compat/php7/auth.php';
require_once $root . '/compat/php7/upload.php';
require_once $root . '/compat/php7/news.php';
require_once $root . '/compat/php7/users.php';
require_once $root . '/compat/php7/interactions.php';
require_once $root . '/compat/php7/cloudsave.php';
require_once $root . '/compat/php7/profile.php';
require_once $root . '/compat/php7/social.php';
require_once $root . '/compat/php7/levels.php';

try {
    $settings = mucho7_load_settings($root . '/config/php7-local.php');
    if(ob_get_length()>0)ob_clean();
    if (!is_array($settings)) {
        throw new RuntimeException('No configuration');
    }

    $options = array(
        'enable_accounts'=>!empty($settings['enable_accounts']),
        'enable_registration'=>!empty($settings['enable_registration']) && !is_file($root.'/config/registrations-disabled.flag'),
        'enable_writes'=>!empty($settings['enable_writes']),
        'enable_cloudsave'=>!empty($settings['enable_cloudsave']),
        'enable_social'=>!empty($settings['enable_social']),
        'enable_native_news'=>!empty($settings['enable_native_news']),
        'site_url'=>isset($settings['site_url']) ? $settings['site_url'] : '',
        'news_file'=>$root . '/config/php7-news.json',
        'auth_pepper'=>isset($settings['auth_pepper']) ? $settings['auth_pepper'] : '',
        'ip'=>isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : ''
    );
    $path = mucho7_path($uri);
    if(is_file($root.'/config/maintenance.flag') && !in_array($path,array('/health','/checkifserveronline'),true)){
        http_response_code(503);echo '-1';exit;
    }

    $newsSql=is_file($root.'/config/php7-news-sql.on');
    if(preg_match('~^/(?:gd-content/)?news/(mcnews_[a-f0-9]{64}\.jpg)$~D',$path,$imageMatch)
        || $path==='/news/image') {
        if(!$newsSql){http_response_code(404);exit;}
        $file=isset($imageMatch[1])?$imageMatch[1]:(isset($_GET['file']) && is_string($_GET['file'])?$_GET['file']:'');
        \MuchoCore\News\NewsImageResponse::send(mucho7_connect($settings),$file);
    }
    if(strpos($path,'/gd-content/')===0&&!in_array($path,array('/gd-content/news/news.dat','/gd-content/news/news_version.txt'),true)){
        if(empty($settings['enable_native_news'])){http_response_code(404);exit;}
        require_once $root.'/compat/php7/content-proxy.php';mucho7_content_send($uri,$method,$_SERVER);
    }
    if($method==='HEAD'){http_response_code(405);header('Allow: GET, POST');exit;}

    // Legacy text news stays database-independent until the owner migrates it.
    if (in_array($path, array(
        '/getcustomcontenturl', '/getaccounturl',
        '/news/news.dat', '/news/news_version.txt',
        '/gd-content/news/news.dat', '/gd-content/news/news_version.txt'
    ), true)) {
        if($newsSql && in_array($path,array('/news/news.dat','/news/news_version.txt','/gd-content/news/news.dat','/gd-content/news/news_version.txt'),true))
            $options['news_pdo']=mucho7_connect($settings);
        echo mucho7_dispatch(null, $uri, $method, $_POST, $options);
        exit;
    }

    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('PDO MySQL extension unavailable');
    }
    foreach (array('host','port','database','username','password') as $key) {
        if (!isset($settings[$key]) || !is_scalar($settings[$key])) {
            throw new RuntimeException('Incomplete configuration');
        }
    }
    $host = (string)$settings['host'];
    $port = (int)$settings['port'];
    $database = (string)$settings['database'];
    if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/D', $host)
        || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database)
        || $port < 1 || $port > 65535) {
        throw new RuntimeException('Invalid configuration');
    }
    $dsn = 'mysql:host=' . $host . ';port=' . $port
        . ';dbname=' . $database . ';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)$settings['username'], (string)$settings['password'],
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3
        )
    );
    $pdo->exec("SET time_zone='+00:00'");
    if(is_file($root.'/config/php7-complete.on')){
        require_once $root.'/compat/php7/domain.php';
        if(!mucho7_domain_complete($pdo))throw new RuntimeException('Core data upgrade incomplete.');
        if($path==='/lostusername'){
            if(empty($settings['enable_accounts'])||empty($settings['enable_writes'])){http_response_code(503);echo '-1';exit;}
            header('Content-Type: text/html; charset=utf-8');echo mucho7_domain_recovery($pdo,$settings,$root);exit;
        }
        mucho7_domain_handle($pdo,$uri,$method,$_POST,$settings,$root)->send();
    }
    echo mucho7_dispatch($pdo, $uri, $method, $_POST, $options);
} catch (Exception $exception) {
    // A public API must never disclose the DB name, password or SQL statement.
    error_log('[MuchoCore PHP7 experimental] Request rejected (configuration or schema unavailable)');
    http_response_code(503);
    echo '-1';
} catch (Error $exception) {
    error_log('[MuchoCore PHP7 experimental] Runtime error');
    http_response_code(503);
    echo '-1';
}
