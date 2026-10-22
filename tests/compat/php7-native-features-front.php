<?php
/** Exercise the actual native entry point against the disposable test DB. */
declare(strict_types=1);

function nativeFeatureFront($settings, $auth)
{
    $root = dirname(__DIR__,2);
    $stage = sys_get_temp_dir() . '/mucho7-front-' . bin2hex(random_bytes(8));
    mkdir($stage . '/public',0700,true);
    mkdir($stage . '/config',0700,true);
    mkdir($stage . '/compat',0700,true);
    symlink($root . '/compat/php7', $stage . '/compat/php7');
    copy($root . '/public/php7.php', $stage . '/public/php7.php');
    file_put_contents($stage . '/public/php7-mode.on','');
    file_put_contents($stage . '/config/php7-local.php', '<?php return ' . var_export($settings,true) . ';');
    file_put_contents($stage . '/request.php', <<<'PHP'
<?php
$request = json_decode(stream_get_contents(STDIN),true);
$_SERVER = array('HTTPS'=>'on','SERVER_PORT'=>'443',
    'REQUEST_METHOD'=>isset($request['method']) ? $request['method'] : 'POST',
    'SCRIPT_NAME'=>'/mc/public/php7.php', 'REQUEST_URI'=>$request['uri'],
    'CONTENT_LENGTH'=>$request['length'], 'REMOTE_ADDR'=>'127.0.0.1');
$_POST = $request['post'];
require __DIR__ . '/public/php7.php';
PHP
    );
    $ini = php_ini_loaded_file();
    $command = escapeshellarg(PHP_BINARY)
        . ($ini ? ' -c '.escapeshellarg($ini) : ' -n')
        . ' -d memory_limit=128M ' . escapeshellarg($stage . '/request.php');
    $call = function ($uri, $post, $length = 0, $method = 'POST') use ($command) {
        $pipes = array();
        $process = proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
        if (!is_resource($process)) { throw new RuntimeException('Unable to run native front controller'); }
        fwrite($pipes[0],json_encode(array('uri'=>$uri,'post'=>$post,'length'=>$length,'method'=>$method)));
        fclose($pipes[0]);
        $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($process);
        nativeFeatureAssert($code===0 && $errors==='', 'native front must complete without runtime errors');
        return $body;
    };
    try {
        nativeFeatureAssert($call('/mc/health',array(),0,'GET')==='1','mounted front health');
        nativeFeatureAssert(strpos($call('/mc/a/getGJUserInfo20.php',$auth),'1:Owner:2:42')===0,'mounted authenticated profile front');
        nativeFeatureAssert(strpos($call('/a/getGJUserInfo20.php',$auth),'1:Owner:2:42')===0,'root alias authenticated profile front');
        $backup = array_merge($auth,array('saveData'=>str_repeat('a',300*1024)));
        nativeFeatureAssert($call('/mc/accounts/backupGJAccount20.php',$backup,301*1024)==='1','cloudsave front exceeds old 256 KiB request limit');
        nativeFeatureAssert($call('/accounts/syncGJAccount20.php',$auth) === $backup['saveData'].';21;30;a;a','cloudsave front roundtrip');
        nativeFeatureAssert($call('/mc/accounts/backupGJAccount20.php',$backup,26*1024*1024)==='-1','cloudsave front rejects oversized encoded request');
        nativeFeatureAssert($call('/mc/updateGJUserScore22.php',$auth,300*1024)==='-1','large cap is restricted to cloud backup');
    } finally {
        unlink($stage . '/request.php');
        unlink($stage . '/public/php7.php');
        unlink($stage . '/public/php7-mode.on');
        unlink($stage . '/config/php7-local.php');
        unlink($stage . '/compat/php7');
        rmdir($stage . '/public'); rmdir($stage . '/config');
        rmdir($stage . '/compat'); rmdir($stage);
    }
}
