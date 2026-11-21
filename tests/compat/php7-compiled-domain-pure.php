<?php
/** Execute actual original classes after lowering; also runs on PHP 7.0. */
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/compat/php7/domain.php';
function domainCheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
$sources=json_decode(file_get_contents($root.'/compat/php7/core/sources.json'),true);
// PHP 8-only message diagnostics and native Android APK variant support
// remain deliberately outside the pinned PHP 7 compiled snapshot while PHP 8
// is the sole development priority. Pin the exact source hashes: any further
// changes fail this guard until the snapshot is explicitly recompiled.
// PHP 7 retains its previous, verified single-build Android patcher.
$php8OnlyDiagnosticHashes=array(
    // PHP 8 endpoint review. These exact revisions are pending the PHP 7 port.
    'src/Account/AccountService.php'=>'d47cd2ba509f5e736cd70a2db406d855cf99bd5d6994b6ba1f6d47dca3684438',
    'src/CloudSave/CloudSaveService.php'=>'7bf9310038af5eb52b0a1ce8d5fbc6a216448f56f79f6bd3d54ceb469afc129d',
    'src/Compatibility/DiscoveryController.php'=>'28a8bd39a57976c72153ea54bd0335b0dd3f719f3718d7ae807bc564649fe395',
    'src/LevelList/LevelListRepository.php'=>'4dee38efcc2598f37fc7cdf4d1ee30a680c1ad8ce9d6d5887f57ec7560267370',
    'src/LevelList/LevelListService.php'=>'aa81001b03ebbc6df3db1825b58faca828d64c7cb7068799ff65d622729d3327',
    'src/Social/RelationshipController.php'=>'39d4ad9311e012d6ebc7588bd1ae71e2e07b2e2291bd12f2fc5a67517e8892a1',
    'src/Social/RelationshipRepository.php'=>'b5e1fbeaa789c425727a194d34959334f7aaa138e8c022d31201e7729b1ce8ff',
    'src/Social/RelationshipService.php'=>'3cc77f9b12097556adc5cde1e12d667f5c244d8f207efa3c6d97edaad58bba79',
    'tests/application/gd2209-gameplay-schema-integration.php'=>'0f24048f3757f9abb8001dbb8fd50e3a652c658dd7e35d3adc014e9234510856',
    // PHP 8 webhook SSRF hardening; PHP 7 compiled runtime remains unchanged.
    'src/Integration/WebhookDispatcher.php'=>'c330659d2243e22f2f75a1dee1cf5f1cc1d97d91dfe4d3f73a06bfbe3a51b6f5',
    // PHP 8 routing-aware abuse budgets; keep the last verified PHP 7 snapshot pinned.
    'src/Security/MuchoProtect.php'=>'2e20059ca9b82100dc4b05d153ee7badd30504fd9478ec99ffa4635d23c1e112',
    // PHP 8 admin copy is English-only; the native PHP 7 compiled snapshot remains frozen.
    'public/admin/advanced-module.php'=>'1fff523ddae413cbc47e4eb5a11b9ac8a7cc3f29b8578b405e97c49916a23eb0',
    // PHP 8-first native profile reporting. The PHP 7 generated snapshot is
    // intentionally held constant until the separate PHP 7 port is scheduled.
    // Pin exact PHP 8 source content: future edits still fail this guard.
    'tests/application/gd2209-case-actions-integration.php'=>'c771e76d0b7ed2b25e756869539d283dd75e38f851b481fb1eee1e74154d5fd9',
    'tests/application/gd2209-report-queue-integration.php'=>'820f7c2109b399a5f4816c4289c797100d249fecaef3692845317fae142a6dae',
    'src/Moderation/Gd2209CaseService.php'=>'c57e1836b7edaf31d60e66d8daea92cf78948ba24d6bb5061560ad026a991aad',
    'src/Moderation/ReportQueueRepository.php'=>'af4189c92ad58fbe80529cff48424ea374a184136993ab7f4a4659b302a96901',
    'src/Moderation/ReportQueueService.php'=>'699f44e17d3c4b5c64a17e0e10525ade9460e3cb2d04347fe7b330416bac747c',
    'src/Client/AndroidGd2209Binary.php'=>'8c0dcb95a744fba6dbb7a59222c3ae12cc25f6ff7ad35ee83ed85c9b9ea601f7',
    'src/Client/AndroidClientPatcher.php'=>'5b201600cff430ba777df3403f403686a89e35e55e965943c69d7013fca72e9c',
    'src/Social/MessageController.php'=>'20146708112462034cbe140a60a553a5e344a6bdbf3d20b1430c5059c82a660b',
    'src/Social/MessageService.php'=>'3d42dfed2b6cd97f3c8db974aabb8f1aee0ec59da6f95bdc4f388bd9fced4e86',
    // PHP 8 request bootstrap and authentication optimizations.
    'src/Account/AccountAuthenticator.php'=>'df4c34f8cc1624fe475e1e7dff79f8bf14a113a8cf646155490e24788d23b9a0',
    'src/Core/Application.php'=>'44e8139178cc22edb7936c77b707d6256c69c9d6baac967656188527c3600998',
    'src/Level/LevelTransferRepository.php'=>'5f2f621c0d176e7fa4da8fd1cb52008c7fb2674b668193913579c7220edabbd8',
    'src/Level/LevelTransferService.php'=>'186cc53eb16137e9cc34e8e6a10e39ef8a2a8481f90b47e0ade50221a6b234b2',
    'src/Routing/Router.php'=>'4a41233fbc2aa91ab6caa38b4f92dbca3169c50014e0b5f0e1fdbfa00387b110',
    'src/User/UserRepository.php'=>'e777296cda69f58974d65ffc77158d9f68c242fcb625081e665bff680a53d648',
);
$count=0;
foreach($sources as $path=>$hash){
    $actual=hash_file('sha256',$root.'/'.$path);
    $tracked=hash_equals($hash,$actual);
    $php8Only=isset($php8OnlyDiagnosticHashes[$path])
        && hash_equals($php8OnlyDiagnosticHashes[$path],$actual);
    domainCheck($tracked||$php8Only,'Original source changed: '.$path);
    if(strpos($path,'src/')!==0)continue;
    $class='MuchoCore\\'.str_replace('/','\\',substr($path,4,-4));
    domainCheck(class_exists($class)||interface_exists($class)||trait_exists($class),'Compiled class absent: '.$class);$count++;
}
$request=new \MuchoCore\Http\Request('POST','/test',array(),array('gameVersion'=>'22','binaryVersion'=>'51','gjp2'=>'fixture','id'=>'7'),array());
domainCheck($request->postInt('id')===7 && $request->gdCredential()==='fixture','promoted constructor or named argument lowering');
domainCheck(\MuchoCore\Http\Response::json(array('ok'=>true))->body==='{"ok":true}','JSON response');
$router=new \MuchoCore\Routing\Router();$router->add('POST','/fixture',function($request){return \MuchoCore\Http\Response::text('compiled-router');});
domainCheck($router->dispatch(new \MuchoCore\Http\Request('POST','/fixture',array(),array(),array()))->body==='compiled-router','PHP7 Closure callable route dispatch');
domainCheck(\MuchoCore\Protocol\Gd2209ThreadWire::messages(array(array('id'=>1,'sender_account_id'=>7,'body'=>'hello','created_at'=>'2026-10-23 00:00:00')))!=='none','first-class method callable');
domainCheck(\MuchoCore\Protocol\Gd2209ThreadWire::relativeTime(100,3700)==='1 hour','list destructuring');
domainCheck(\MuchoCore\Compatibility\ClientVersion::fromValues(22,51)->usesGjp2(),'match expression');
domainCheck(mucho7_json_decode('{"x":1}',true,512,JSON_THROW_ON_ERROR)===array('x'=>1),'JSON decode');
try{mucho7_json_decode('{broken',true,512,JSON_THROW_ON_ERROR);throw new LogicException('Invalid JSON accepted');}catch(JsonException $error){}
try{mucho7_json_encode(array('bad'=>"\xff"),JSON_THROW_ON_ERROR);throw new LogicException('Invalid UTF-8 accepted');}catch(JsonException $error){}
domainCheck(mucho7_json_encode(array('bad'=>"\xff"),JSON_INVALID_UTF8_SUBSTITUTE)==='{"bad":"\\ufffd"}','UTF-8 substitution');
echo 'PHP7_COMPILED_ORIGINAL_CLASSES_OK '.$count."\n";
