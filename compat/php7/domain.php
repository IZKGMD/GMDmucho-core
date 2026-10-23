<?php
/** Load compiled original domain classes without Composer or a VPS. PHP 7.0+. */
declare(strict_types=1);
if (!defined('MUCHO7_PROJECT_ROOT')) define('MUCHO7_PROJECT_ROOT',dirname(__DIR__,2));
if (!defined('MUCHO7_COMPILED_ROOT')) define('MUCHO7_COMPILED_ROOT',__DIR__.'/core');
require_once __DIR__.'/domain-polyfills.php';
function mucho7_compiled_include($file)
{
    $parts=array();foreach(explode('/',str_replace('\\','/',(string)$file)) as $part){if($part===''||$part==='.')continue;if($part==='..'){array_pop($parts);continue;}$parts[]=$part;}
    $file='/'.implode('/',$parts);$root=MUCHO7_PROJECT_ROOT.'/';
    if(strpos($file,$root)===0){
        $relative=substr($file,strlen($root));
        if($relative==='vendor/autoload.php')return __DIR__.'/core/autoload.php';
        $compiled=__DIR__.'/core/'.$relative;
        if(is_file($compiled))return $compiled;
        if(strpos($relative,'src/')===0 || strpos($relative,'database/migrations/')===0)throw new RuntimeException('Original source has not been compiled.');
    }
    return $file;
}
spl_autoload_register(function($class){
    $prefix='MuchoCore\\';
    if(strncmp($class,$prefix,strlen($prefix))!==0)return;
    $relative=substr($class,strlen($prefix));
    if(!preg_match('/^[A-Za-z0-9_\\\\]+$/D',$relative))return;
    $file=__DIR__.'/core/src/'.str_replace('\\','/',$relative).'.php';
    if(is_file($file))require_once $file;
});
