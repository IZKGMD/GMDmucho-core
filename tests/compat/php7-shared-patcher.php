<?php
/** Actual resumed jobs, independent of ext-zip or all process functions. */
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/compat/php7/bootstrap.php';
require $root.'/compat/php7/domain.php';
require $root.'/compat/php7/patcher/Job.php';
function patchCheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
function patchReject($call,$why){$bad=false;try{$call();}catch(RuntimeException $e){$bad=true;}patchCheck($bad,$why);}
$input=getenv('SHARED_PATCHER_APK_FIXTURE');$work=getenv('SHARED_PATCHER_TEST_ROOT');
if(!$input||!is_file($input)||!$work||is_dir($work))throw new RuntimeException('Provide a fixture APK and a NEW test root.');
mkdir($work.'/config',0700,true);
$original=hash_file('sha256',$input);$bytes=file_get_contents($input);$size=strlen($bytes);
$fingerprint=hash('sha256',substr($bytes,0,65536).substr($bytes,max(0,$size-65536)));
$s=MuchoSharedPatcherJob::start($work,7,'fixture.apk',$size,'https://example.com',false,$fingerprint);$id=$s['id'];
$dir=MuchoSharedPatcherJob::directory($work,$id);
patchReject(function()use($work,$id){MuchoSharedPatcherJob::step($work,8,$id);},'Another administrator entered the job.');
patchReject(function()use($work,$id){MuchoSharedPatcherJob::chunk($work,7,$id,0,'invalid',str_repeat('0',64));},'Corrupt chunk was accepted.');
$first=substr($bytes,0,1048576);$s=MuchoSharedPatcherJob::chunk($work,7,$id,0,$first,hash('sha256',$first));
$again=MuchoSharedPatcherJob::chunk($work,7,$id,0,$first,hash('sha256',$first));patchCheck($again['offset']===$s['offset'],'Lost-response retry appended twice.');
for($offset=$s['offset'];$offset<$size;){$part=substr($bytes,$offset,1048576);$s=MuchoSharedPatcherJob::chunk($work,7,$id,$offset,$part,hash('sha256',$part));$offset=$s['offset'];}
unset($bytes,$part,$first);
$stages=array();$steps=0;
while($s['phase']!=='done'){
    $before=$s['phase'];$started=microtime(true);$s=MuchoSharedPatcherJob::step($work,7,$id);$steps++;$stages[$s['phase']]=true;
    patchCheck(microtime(true)-$started<25,'A patch step exceeded the shared-host request budget.');
    if($s['phase']==='rebuild'){file_put_contents($dir.'/unsigned','interrupted-write',FILE_APPEND);file_put_contents($dir.'/manifest','interrupted-write',FILE_APPEND);}
    patchCheck($steps<200,'Patcher did not make progress.');
}
patchCheck(isset($stages['digest'],$stages['assemble']),'APK work was not resumable.');
patchCheck($s['report']['replacement_count']>0&&$s['report']['signature_schemes']===array('v1','v2'),'APK was not patched and signed.');
patchCheck(hash_file('sha256',$input)===$original,'Original APK changed.');
patchCheck(hash_file('sha256',$dir.'/result.apk')===$s['report']['output_sha256'],'Output checksum mismatch.');
$entries=MuchoSharedZip::entries($dir.'/result.apk');$names=array_column($entries,'name');
patchCheck(!in_array('META-INF/OLD.SF',$names,true)&&in_array('META-INF/MUCHO.RSA',$names,true),'Stale signature remained.');
foreach($entries as $e){
    if($e['method']===0)patchCheck($e['data']%(substr($e['name'],-3)==='.so'?16384:4)===0,'Unaligned stored entry.');
    if($e['name']==='lib/arm64-v8a/libcocos2dcpp.so'){list($raw,$native)=MuchoSharedZip::read($dir.'/result.apk',$e);patchCheck(strpos($native,'example.com')!==false&&strpos($native,'www.boomlings.com')===false,'Native API address was not replaced.');}
}
$one=MuchoSharedApkSigner::identity($work);$two=MuchoSharedApkSigner::identity($work);patchCheck($one['der']===$two['der'],'APK update identity changed.');
copy($dir.'/result.apk',$work.'/signed.apk');
// Mutations must be rejected by the independent Android verifier in CI.
copy($dir.'/result.apk',$work.'/tampered.apk');$f=fopen($work.'/tampered.apk','r+b');fseek($f,100);$b=fread($f,1);fseek($f,100);fwrite($f,chr(ord($b)^1));fclose($f);
echo 'SHARED_PATCHER_OK steps='.$steps.' output='.$work."/signed.apk\n";
