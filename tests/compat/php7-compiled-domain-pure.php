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
    'src/Compatibility/DiscoveryController.php'=>'10edd5aabecad5adcb68cdd60251674814cb7a52656f6f7335b83ddbffc29063',
    'src/LevelList/LevelListRepository.php'=>'4dee38efcc2598f37fc7cdf4d1ee30a680c1ad8ce9d6d5887f57ec7560267370',
    'src/LevelList/LevelListService.php'=>'aa81001b03ebbc6df3db1825b58faca828d64c7cb7068799ff65d622729d3327',
    'src/Social/RelationshipController.php'=>'39d4ad9311e012d6ebc7588bd1ae71e2e07b2e2291bd12f2fc5a67517e8892a1',
    'src/Social/RelationshipRepository.php'=>'b5e1fbeaa789c425727a194d34959334f7aaa138e8c022d31201e7729b1ce8ff',
    'src/Social/RelationshipService.php'=>'3cc77f9b12097556adc5cde1e12d667f5c244d8f207efa3c6d97edaad58bba79',
    'tests/application/gd2209-gameplay-schema-integration.php'=>'82e961818e9e0583ca24d5abc6ba819649c8d0ac2ee46368f1b6aa285ba1d877',
    // PHP 8 webhook SSRF hardening; PHP 7 compiled runtime remains unchanged.
    'src/Integration/WebhookDispatcher.php'=>'c330659d2243e22f2f75a1dee1cf5f1cc1d97d91dfe4d3f73a06bfbe3a51b6f5',
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
    'src/Level/LevelTransferRepository.php'=>'5d3fce996b77d2e0216d46d8e04804866ac98cfc7646835df812682c4c2349fb',
    'src/Level/LevelTransferService.php'=>'aa683406c7e5ab11c17bd56dbdd2300ffeffda86491d1c9d04b0c8f0ddef781a',
    'src/Routing/Router.php'=>'d67bce97a1168d7682f2a9010b05f2a4ef4c0e54f170a2204817f6e011e85751',
    'src/Level/LevelDownloadTracker.php'=>'81a0a1314d86793d76961e4a4cf4b90b0f9ffe7b9a79f0951a63fb13ddd0e290',
    // Native Windows 2.209.1 acceptance fixes in PHP 8.
    'src/Client/Gd2209Binary.php'=>'f149799654efc57fe47df9c6f8bf811602343ca187dd3b20fec1f7c5e12bcb85',
    'src/Comment/CommentHistoryService.php'=>'4308ca747433d2b156cb9e7aeff89e88661513aebf8e4c94536f68c29b1529fc',
    'src/Interaction/CommentRepository.php'=>'49f44575467becfaa4210f46a2489a502cbec2c5adda76dca8ba4068d6a15343',
    'src/Interaction/RewardsService.php'=>'9c924049d86732c2d94c0e341f88ba2ef077d88d15b0b15884b48897dc62de34',
    'src/Protocol/Gd2209ThreadWire.php'=>'ee7277d71dc5524937f8dd272af4c42af53ac1737135a58c0fa6e1f4cbbf831c',
    'src/Protocol/GdCommentEncoder.php'=>'0687a6e6be2335301b62c9b80b5762e226e48796776cb3f29c2d1d10965b032a',
    'src/Protocol/ProtocolText.php'=>'3f23cd6158b6dcc6116e871c6490948dc4a67aac66a5969e6ed0daa685a05a06',
    'src/Score/LevelScoreController.php'=>'22e26c358f19ed939671ffcfa184898bb1b8e5479143ba45b82fb67b7f1163b0',
    'src/Social/ThreadService.php'=>'736d728e9905b96f59036b7090a0d637167a86357a5ae9382fb2af094560a81e',
    'tests/application/gd2209-thread-integration.php'=>'40b72d2aeba970da2d1325c5d47f830bd2295fb3b27c9a03e25df92e524cc3a0',
    'src/Level/LevelRepository.php'=>'86e673b56e97e09767a4b54f2bba968e0f07467adcd453d72f9e779dac879f4e',
    'src/Protocol/GdLevelListEncoder.php'=>'92dcc92c8f1bd0f93a9c280b8bdc0760931ef6e6f48dc75cb2e4a4b54603efe1',
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
