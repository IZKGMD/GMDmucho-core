<?php
/** Verify immutable code installation, tamper rejection and runtime bindings. */
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/compat/php7/bootstrap.php';
require $root.'/compat/php7/core-update.php';
function packCheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
function packRemove($path){if(is_dir($path)){foreach(new DirectoryIterator($path) as $file)if(!$file->isDot())packRemove($file->getPathname());rmdir($path);}else unlink($path);}
$stage=sys_get_temp_dir().'/mc_pack_'.bin2hex(random_bytes(6));mkdir($stage.'/config',0700,true);mkdir($stage.'/compat/php7',0700,true);
try{
    $argv=array(__FILE__,$stage.'/config');
    // The builder has its own $root: isolate its variables from this fixture.
    (function($argv){require dirname(__DIR__,2).'/tools/release/build-php7-core-pack.php';})($argv);
    $count=mucho7_core_install_pack($stage);packCheck($count>270,'Missing original domains.');
    packCheck(mucho7_core_install_pack($stage)===$count,'Installation is not idempotent.');
    $build=json_decode(file_get_contents($stage.'/config/php7-core-build.json'),true);
    $directory=$stage.'/compat/php7/builds/'.$build['sha256'];
    packCheck(!is_dir($directory.'/core/tests'),'Tests shipped in production.');
    packCheck(is_file($directory.'/core/src/Core/Application.php'),'Original application missing.');
    packCheck(!mucho7_core_pack_path('../config/php7-local.php')&&!mucho7_core_pack_path('core/src/../../shell.php')&&!mucho7_core_pack_path('core/tests/test.php'),'Unsafe file accepted.');
    // Loading from a versioned snapshot must resolve original configs/classes.
    copy($root.'/compat/php7/domain.php',$stage.'/compat/php7/domain.php');
    define('MUCHO7_PROJECT_ROOT',$stage);
    require $stage.'/compat/php7/domain.php';
    packCheck(class_exists('MuchoCore\\Core\\Application'),'Versioned class loader failed.');
    packCheck(is_array(require mucho7_compiled_include($stage.'/config/mucho_v71.php')),'Versioned config mirror missing.');
    packCheck(mucho7_compiled_include($stage.'/vendor/autoload.php')===$directory.'/core/autoload.php','Versioned autoload escaped snapshot.');
    $pointer=file_get_contents($stage.'/config/php7-core-build.json');
    file_put_contents($stage.'/config/php7-core-update.pack','tampered',FILE_APPEND);
    $rejected=false;try{mucho7_core_install_pack($stage);}catch(RuntimeException $e){$rejected=true;}
    packCheck($rejected&&file_get_contents($stage.'/config/php7-core-build.json')===$pointer,'Tampering changed active build.');
    $lock=mucho7_core_lock($stage,true);$other=false;
    try{$other=mucho7_core_lock($stage,false);}catch(RuntimeException $e){}
    packCheck($other===false,'API entered an exclusive update.');flock($lock,LOCK_UN);fclose($lock);
    echo "PHP7_CORE_PACK_VERIFIED\n";
}finally{packRemove($stage);}
