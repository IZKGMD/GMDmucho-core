<?php
/** Serve the original player portal compiled for PHP 7, including /mc mounts. */
declare(strict_types=1);

function mucho7_player_route($path)
{
    if(preg_match('~^/music/[a-f0-9]{40}\.mp3$~D',$path))return 'music';
    $routes=array('/dashboard'=>'public/dashboard/index.php',
        '/dashboard/index'=>'public/dashboard/index.php',
        '/dashboard/clans'=>'public/dashboard/clans.php',
        '/profile-image'=>'public/profile-image.php','/clients'=>'public/clients.php');
    return isset($routes[$path])?$routes[$path]:null;
}

function mucho7_player_url($url)
{
    $mount=isset($GLOBALS['mucho7_domain_mount'])?$GLOBALS['mucho7_domain_mount']:'';
    if(preg_match('~^/(?:assets/|muchocore-theme\.)~',$url))return $mount.'/public'.$url;
    if(preg_match('~^/(?:dashboard(?:[/?#]|$)|clients(?:[/?#]|$)|profile-image\.php(?:[?#]|$)|music/)~',$url))return $mount.$url;
    return $url;
}

function mucho7_player_header($value)
{
    if(stripos($value,'Location:')===0)$value='Location: '.mucho7_player_url(trim(substr($value,9)));
    header($value);
}

function mucho7_player_session_start()
{
    session_start();
    $account=isset($_SESSION['account'])&&is_array($_SESSION['account'])?$_SESSION['account']:null;
    if(!$account)return;
    $db=mucho7_domain_connection();
    $query=$db->prepare('SELECT 1 FROM accounts WHERE account_id=? AND is_active=1 AND is_banned=0');
    $query->execute(array(isset($account['id'])?(int)$account['id']:0));
    if(empty($GLOBALS['mucho7_player_accounts'])||!$query->fetchColumn()){
        unset($_SESSION['account']);session_regenerate_id(true);
    }
}

function mucho7_player_html($html)
{
    // Rewrite local URL attributes only. Player text, remote links and image bytes
    // are never searched/replaced as if they were routing configuration.
    return preg_replace_callback('~\b(href|src|action|data-fallback-src)=("|\x27)(/[^"\x27]*)(\2)~i',function($match){
        return $match[1].'='.$match[2].mucho7_player_url($match[3]).$match[4];
    },$html);
}

function mucho7_player_send(PDO $db,$path,$settings,$root,$method)
{
    header('Content-Type: text/html; charset=utf-8');
    if(!is_file($root.'/config/php7-complete.on')){
        http_response_code(503);
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Player Dashboard</title><h1>Player Dashboard is not ready</h1><p>The server owner must complete the core data upgrade in the admin panel.</p></html>';
        return;
    }
    require_once $root.'/compat/php7/domain.php';
    mucho7_domain_configure($settings,$root,$db);
    $GLOBALS['mucho7_player_accounts']=!empty($settings['enable_accounts']);
    $GLOBALS['mucho7_domain_environment']['MUCHO_ACCOUNT_URL']=rtrim((string)$settings['site_url'],'/').$GLOBALS['mucho7_domain_mount'];
    if(mucho7_player_route($path)==='music'){
        $directory=realpath($root.'/storage/music-public');
        $file=$directory===false?false:realpath($directory.'/'.basename($path));
        $query=$db->prepare('SELECT 1 FROM songs WHERE download_url=? AND is_verified=1 LIMIT 1');
        $query->execute(array($GLOBALS['mucho7_domain_environment']['MUCHO_ACCOUNT_URL'].$path));
        if(!$file||strpos($file,$directory.DIRECTORY_SEPARATOR)!==0||!is_file($file)||!$query->fetchColumn()){
            http_response_code(404);return;
        }
        header('Content-Type: audio/mpeg');header('Content-Length: '.filesize($file));
        if($method!=='HEAD')readfile($file);
        return;
    }
    $file=MUCHO7_COMPILED_ROOT.'/'.mucho7_player_route($path);
    if(!is_file($file)){
        http_response_code(503);
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Player Dashboard</title><h1>Player Dashboard update required</h1><p>The server owner must apply the current core code update in the admin panel.</p></html>';
        return;
    }
    if($method==='HEAD' && $path!=='/profile-image')return;
    if($method==='POST'){
        $action=isset($_POST['action'])&&is_string($_POST['action'])?$_POST['action']:'';
        if(!in_array($path,array('/dashboard','/dashboard/index','/dashboard/clans'),true)
            || ($action!=='logout' && (empty($settings['enable_accounts'])
                || ($action!=='login' && empty($settings['enable_writes']))
                || ($path==='/dashboard/clans' && empty($settings['enable_social']))))){
            http_response_code(403);echo 'Player action unavailable.';return;
        }
    }
    // Keep player sessions independent from other GDPS installations and admins.
    ini_set('session.cookie_path',($GLOBALS['mucho7_domain_mount']?:'').'/');
    $binary=$path==='/profile-image'||($path==='/clients' && isset($_GET['download']));
    if(!$binary)ob_start('mucho7_player_html');
    require $file;
    if(!$binary)ob_end_flush();
}
