<?php
/** Provider cron entry point. Never exposes an HTTP trigger or secret token. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__,2);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/core-update.php';
require_once __DIR__.'/scheduler.php';
try{
    if(!is_file($root.'/config/php7-local.php'))throw new RuntimeException('Private hosting configuration is missing.');
    $settings=require $root.'/config/php7-local.php';
    $runtimeLock=mucho7_core_lock($root,false);
    try{$result=mucho7_shared_schedule(mucho7_connect($settings),$root,$settings,in_array('--backup',$argv,true));}
    finally{flock($runtimeLock,LOCK_UN);fclose($runtimeLock);}
    echo json_encode($result,JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,'Shared scheduler failed. Check the private hosting configuration and server log.' . "\n");error_log('[MuchoCore Shared scheduler] '.get_class($e));exit(1);}
