<?php
/** PHP 7.0 compatible, dependency-free hosting preflight regression tests. */
declare(strict_types=1);
require_once dirname(__DIR__,2).'/compat/php7/readiness.php';

function preflightAssert($yes,$description)
{
    if(!$yes)throw new RuntimeException($description);
}
function preflightStatus($checks,$title)
{
    foreach($checks as $check)if($check['title']===$title)return $check['status'];
    throw new RuntimeException('Missing check: '.$title);
}
preflightAssert(mucho7_readiness_ini_bytes('26M')===26*1048576,'26M parser');
preflightAssert(mucho7_readiness_ini_bytes('128m')===128*1048576,'128M parser');
preflightAssert(mucho7_readiness_ini_bytes('1G')===1073741824,'1G parser');
preflightAssert(mucho7_readiness_ini_bytes('-1')===-1,'unlimited parser');
preflightAssert(mucho7_readiness_ini_bytes('0')===0,'zero parser');
preflightAssert(mucho7_readiness_ini_bytes('garbage')===null,'invalid parser');
preflightAssert(mucho7_readiness_ini_bytes('8M;evil')===null,'malformed ini parser');
preflightAssert(mucho7_readiness_ini_bytes('999999999999999G')===PHP_INT_MAX,'overflow clamp');

$sha=str_repeat('b',64);
$base=array(
    'https'=>true,'site_valid'=>true,'pdo_mysql'=>true,'openssl'=>true,
    'zlib'=>true,'mbstring'=>true,'post_limit'=>'26M','memory_limit'=>'128M',
    'config_writable'=>true,'core_active'=>true,'core_transition'=>false,
    'core_hash'=>$sha,'available_hash'=>$sha,'key_status'=>'valid',
    'accounts'=>true,'writes'=>true,'cloudsave'=>true,'registration'=>true,
);
$checks=mucho7_readiness_evaluate($base);
foreach($checks as $check)preflightAssert($check['status']==='ok',
    'A fully configured fixture must pass '.$check['title']);
$test=array_merge($base,array('post_limit'=>'8M'));
preflightAssert(preflightStatus(mucho7_readiness_evaluate($test),'Save POST allowance')==='error',
    'Small POST limit must block claimed readiness');
$test=array_merge($base,array('memory_limit'=>'64M'));
preflightAssert(preflightStatus(mucho7_readiness_evaluate($test),'PHP memory')==='error',
    'Small memory limit must block claimed readiness');
$test=array_merge($base,array('core_transition'=>true));
preflightAssert(preflightStatus(mucho7_readiness_evaluate($test),'Full game core')==='error',
    'Transition cannot be reported as ready');
$test=array_merge($base,array('core_hash'=>''));
preflightAssert(preflightStatus(mucho7_readiness_evaluate($test),'Uploaded build pending')==='warn',
    'Uploaded but inactive build must be shown');
$test=array_merge($base,array('key_status'=>'missing'));
preflightAssert(preflightStatus(mucho7_readiness_evaluate($test),'Cloud Save encryption key')==='warn',
    'Missing key must not be reported as a verified success');
$test=array_merge($base,array('key_status'=>'invalid'));
preflightAssert(preflightStatus(mucho7_readiness_evaluate($test),'Cloud Save encryption key')==='error',
    'Corrupt key must not be reported as recoverable via rotation');
$test=array_merge($base,array('accounts'=>false,'cloudsave'=>false));
preflightAssert(preflightStatus(mucho7_readiness_evaluate($test),'Game write switches')==='warn',
    'Disabled Cloud Save must not pass');
$test=array_merge($base,array('https'=>false));
preflightAssert(preflightStatus(mucho7_readiness_evaluate($test),'HTTPS origin')==='error',
    'Non-HTTPS owner session must not pass');

$root=sys_get_temp_dir().'/mucho-readiness-'.bin2hex(random_bytes(8));
preflightAssert(mkdir($root.'/config',0700,true),'test directory');
try{
    $path=$root.'/config/cloudsave.key';
    preflightAssert(mucho7_readiness_key_status($path)==='missing','missing key');
    $original=random_bytes(32);
    file_put_contents($path,base64_encode($original)."\n");
    preflightAssert(mucho7_readiness_key_status($path)==='valid','Base64 key');
    file_put_contents($path,bin2hex($original)."\n");
    preflightAssert(mucho7_readiness_key_status($path)==='valid','HEX key');
    file_put_contents($path,'broken');
    preflightAssert(mucho7_readiness_key_status($path)==='invalid','invalid key');
    unlink($path);
    file_put_contents($root.'/config/php7-core-build.json',json_encode(array('sha256'=>$sha)));
    preflightAssert(mucho7_readiness_installed_hash($root.'/config/php7-core-build.json')===$sha,
        'Installed hash');
    file_put_contents($root.'/config/php7-core-build.json', '{"sha256":"bad"}');
    preflightAssert(mucho7_readiness_installed_hash($root.'/config/php7-core-build.json')==='',
        'Invalid installed hash rejected');
    $sample=mucho7_readiness_snapshot($root,array('site_url'=>'https://example.5v.pl',
        'enable_accounts'=>true,'enable_writes'=>true,'enable_cloudsave'=>true));
    preflightAssert($sample['site_valid']===true,'Valid HTTPS site origin');
    preflightAssert(!array_key_exists('db_password',$sample),'No secrets in diagnostic snapshot');
    $sample=mucho7_readiness_snapshot($root,array('site_url'=>'https://example.5v.pl/mc'));
    preflightAssert($sample['site_valid']===false,'Mounted PHP7 path is not a site origin');
    $sample=mucho7_readiness_snapshot($root,array('site_url'=>'https://example.5v.pl?key=secret'));
    preflightAssert($sample['site_valid']===false,'Query and credentials rejected');
} finally {
    foreach(array('cloudsave.key','php7-core-build.json') as $filename){
        if(is_file($root.'/config/'.$filename))unlink($root.'/config/'.$filename);
    }
    rmdir($root.'/config');rmdir($root);
}
echo "PHP7_HOSTING_PREFLIGHT_PURE_OK\n";
