<?php
/** Load compiled original domain classes without Composer or a VPS. PHP 7.0+. */
declare(strict_types=1);
if (!defined('MUCHO7_PROJECT_ROOT')) define('MUCHO7_PROJECT_ROOT',dirname(__DIR__,2));
$domainHome=__DIR__;
$buildFile=MUCHO7_PROJECT_ROOT.'/config/php7-core-build.json';
if(is_file($buildFile)){
    $build=json_decode((string)file_get_contents($buildFile),true);
    if(!is_array($build)||!isset($build['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$build['sha256']))throw new RuntimeException('Invalid installed core metadata.');
    $domainHome=__DIR__.'/builds/'.$build['sha256'];
    if(!is_file($domainHome.'/core/sources.json'))throw new RuntimeException('The installed core files are unavailable.');
}
if (!defined('MUCHO7_DOMAIN_HOME')) define('MUCHO7_DOMAIN_HOME',$domainHome);
if (!defined('MUCHO7_COMPILED_ROOT')) define('MUCHO7_COMPILED_ROOT',MUCHO7_DOMAIN_HOME.'/core');
if (!defined('MUCHO7_VENDOR_ROOT')) define('MUCHO7_VENDOR_ROOT',MUCHO7_DOMAIN_HOME.'/vendor');
require_once MUCHO7_DOMAIN_HOME.'/domain-polyfills.php';
require_once MUCHO7_DOMAIN_HOME.'/domain-schema.php';
require_once MUCHO7_DOMAIN_HOME.'/domain-data.php';
require_once MUCHO7_DOMAIN_HOME.'/domain-runtime.php';
function mucho7_compiled_include($file)
{
    $parts=array();foreach(explode('/',str_replace('\\','/',(string)$file)) as $part){if($part===''||$part==='.')continue;if($part==='..'){array_pop($parts);continue;}$parts[]=$part;}
    $file='/'.implode('/',$parts);$root=MUCHO7_PROJECT_ROOT.'/';
    if(strpos($file,$root)===0){
        $relative=substr($file,strlen($root));
        if($relative==='vendor/autoload.php')return MUCHO7_COMPILED_ROOT.'/autoload.php';
        if(function_exists('adminIsPlain') && $relative==='public/admin/core/AdminUi.php')return __DIR__.'/admin-ui/bridge.php';
        if(function_exists('adminIsPlain') && $relative==='public/admin/core/AdminChrome.php')return __DIR__.'/admin-ui/AdminChrome.php';
        $compiled=MUCHO7_COMPILED_ROOT.'/'.$relative;
        if(is_file($compiled))return $compiled;
        if(strpos($relative,'src/')===0 || strpos($relative,'database/migrations/')===0)throw new RuntimeException('Original source has not been compiled.');
    }
    return $file;
}
spl_autoload_register(function($class){
    foreach(array('ReportUri\\Passkeys\\'=>MUCHO7_VENDOR_ROOT.'/passkeys/','phpseclib3\\'=>MUCHO7_VENDOR_ROOT.'/phpseclib/','ParagonIE\\ConstantTime\\'=>MUCHO7_VENDOR_ROOT.'/constant-time-encoding/') as $vendorPrefix=>$directory){
        if(strpos($class,$vendorPrefix)===0){
            $relative=substr($class,strlen($vendorPrefix));if(!preg_match('/^[A-Za-z0-9_\\\\]+$/D',$relative))return;
            $file=$directory.str_replace('\\','/',$relative).'.php';if(is_file($file))require_once $file;return;
        }
    }
    $prefix='MuchoCore\\';
    if(strncmp($class,$prefix,strlen($prefix))!==0)return;
    $relative=substr($class,strlen($prefix));
    if(!preg_match('/^[A-Za-z0-9_\\\\]+$/D',$relative))return;
    $file=MUCHO7_COMPILED_ROOT.'/src/'.str_replace('\\','/',$relative).'.php';
    if(is_file($file))require_once $file;
});
require_once MUCHO7_VENDOR_ROOT.'/phpseclib/bootstrap.php';
