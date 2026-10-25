<?php
/** Native hosting bindings for the original application, without .env secrets. */
declare(strict_types=1);
function mucho7_domain_connection(){return isset($GLOBALS['mucho7_domain_connection'])?$GLOBALS['mucho7_domain_connection']:null;}
function mucho7_domain_recovery_url(){return isset($GLOBALS['mucho7_domain_recovery_url'])?$GLOBALS['mucho7_domain_recovery_url']:'https://localhost';}
function mucho7_domain_read_only(){return !empty($GLOBALS['mucho7_domain_read_only']);}
function mucho7_domain_environment($key)
{
    return isset($GLOBALS['mucho7_domain_environment'][$key])?$GLOBALS['mucho7_domain_environment'][$key]:null;
}
function mucho7_domain_configure($settings,$root,$db=null)
{
    if($db instanceof PDO)$GLOBALS['mucho7_domain_connection']=$db;
    $site=isset($settings['site_url'])?rtrim((string)$settings['site_url'],'/'):'';
    $script=isset($_SERVER['SCRIPT_NAME'])?$_SERVER['SCRIPT_NAME']:'';
    $mount=strpos($script,'/')===0?rtrim(str_replace('\\','/',dirname($script)),'/.'):'';
    if(substr($mount,-7)==='/public')$mount=substr($mount,0,-7);
    $GLOBALS['mucho7_domain_recovery_url']=$site.$mount;
    $GLOBALS['mucho7_domain_mount']=$mount;
    $GLOBALS['mucho7_domain_environment']=array(
        'MUCHO_SHARED_HOSTING'=>'1','MUCHO_ACCOUNT_URL'=>$site,
        'MUCHO_ADMIN_PASSKEY_RP_ID'=>(string)parse_url($site,PHP_URL_HOST),
        'MUCHO_WEBHOOK_URL'=>!empty($settings['enable_webhooks'])&&isset($settings['webhook_url'])?(string)$settings['webhook_url']:'',
        'MUCHO_WEBHOOK_SECRET'=>isset($settings['webhook_secret'])?(string)$settings['webhook_secret']:'',
        'MUCHO_WEBHOOK_EVENTS'=>isset($settings['webhook_events'])?(string)$settings['webhook_events']:'*',
        'MUCHO_NATIVE_NEWS'=>!empty($settings['enable_native_news'])?'1':'0',
        'MUCHO_CLOUDSAVE_KEY_FILE'=>$root.'/config/cloudsave.key',
        // Keep download deduplication stable across independent PHP requests.
        // Publish only a domain-separated derivation, never the private secret.
        'MUCHO_DOWNLOAD_HMAC_KEY'=>!empty($settings['auth_pepper'])?hash('sha256','muchocore-shared-download|'.$settings['auth_pepper']):(!empty($settings['password'])?hash('sha256','muchocore-shared-download|'.$settings['password']):''),
        'MUCHO_CACHE_DRIVER'=>'file','MUCHO_FILE_CACHE_DIR'=>$root.'/config/php7-core-cache',
        'MUCHO_PROTECT_STORAGE'=>'database','MUCHO_PROTECT_FAIL_OPEN'=>'0',
        'MUCHO_PROTECT_PENALTY_DIR'=>$root.'/config/php7-core-penalties',
        'MUCHO_PROTECT_AUDIT_DIR'=>$root.'/config/php7-core-audit'
    );
}
function mucho7_domain_private_directories($root)
{
    foreach(array('storage','plugins') as $name){
        $directory=$root.'/'.$name;
        if(is_link($directory))throw new RuntimeException('Private core directories must not be symlinks.');
        if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Unable to create private core storage.');
        if(!is_file($directory.'/.htaccess'))mucho7_private_write($directory.'/.htaccess',"Options -Indexes\nRequire all denied\n");
    }
}
/** Honor the native feature switches before invoking any original handler. */
function mucho7_domain_handle(PDO $db,$uri,$method,$post,$settings,$root)
{
    mucho7_domain_configure($settings,$root,$db);
    $GLOBALS['mucho7_domain_read_only']=empty($settings['enable_writes']);
    $path=(new \MuchoCore\Routing\Router())->normalizePath($uri);
    $plain=preg_replace('/(?:19|20|21|211|22)$/','',$path);
    $public=array('/health','/checkifserveronline','/getaccounturl','/getcustomcontenturl','/getgjlevels','/downloadgjlevel',
        '/getgjusers','/getgjusersearch','/getgjuserinfo','/getgjscores','/getgjsonginfo','/getgjcomments','/getgjaccountcomments',
        '/getgjcommenthistory','/getgjdailylevel','/getgjgauntlets','/getgjmappacks','/getgjcreators','/getgjtopartists','/getgjlevellists','/getgjsongusage','/getsavedata');
    if(empty($settings['enable_accounts'])&&!in_array($plain,$public,true))return \MuchoCore\Http\Response::text('-1');
    if($plain==='/backupgjaccount'&&isset($post['saveData'])&&(!is_string($post['saveData'])||strlen($post['saveData'])>8*1024*1024))return \MuchoCore\Http\Response::text('-1');
    if($plain==='/registergjaccount'&&(empty($settings['enable_registration'])||is_file($root.'/config/registrations-disabled.flag')))return \MuchoCore\Http\Response::text('-1');
    $read=in_array($plain,$public,true)||in_array($plain,array('/logingjaccount','/syncgjaccount','/getgjmessages','/downloadgjmessage',
        '/getgjfriendrequests','/getgjuserlist','/getgjthreads','/getgjthreadmessages','/getgjthreadmembers','/getgjthreadinvites',
        '/searchgjthreadmessages','/getgjfollowing','/syncgjfollowing','/getgjnotifications','/getgjeditorsounds','/downloadgjeditorsound',
        '/getgjdeathdata','/getgjlevelcontrols','/getlevelrateinfo'),true);
    if(empty($settings['enable_writes'])&&!$read)return \MuchoCore\Http\Response::text('-1');
    if(in_array($plain,array('/backupgjaccount','/syncgjaccount'),true)&&empty($settings['enable_cloudsave']))return \MuchoCore\Http\Response::text('-1');
    if(empty($settings['enable_social'])&&(preg_match('/(?:friend|message|thread|following|followgj|mutegj|notification)/',$plain)||strpos($path,'/clans/')===0))return \MuchoCore\Http\Response::text('-1');
    $request=new \MuchoCore\Http\Request(strtoupper($method),$uri,$_GET,$post,$_SERVER);
    return (new \MuchoCore\Core\Application($db,$request))->handle($request);
}

function mucho7_domain_recovery(PDO $db,$settings,$root)
{
    mucho7_domain_configure($settings,$root,$db);
    ob_start();try{(new \MuchoCore\Account\RecoveryController())->handle();$html=ob_get_contents();}finally{ob_end_clean();}
    $mount=$GLOBALS['mucho7_domain_mount'];
    foreach(array('/assets/','/recovery/','/muchocore-theme.') as $prefix)$html=str_replace(array('href="'.$prefix,'src="'.$prefix),array('href="'.$mount.'/public'.$prefix,'src="'.$mount.'/public'.$prefix),$html);
    return str_replace('href="/api/api/accounts/lostusername.php','href="'.$mount.'/api/api/accounts/lostusername.php',$html);
}
