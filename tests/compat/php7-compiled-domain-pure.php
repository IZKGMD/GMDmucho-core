<?php
/** Execute actual original classes after lowering; also runs on PHP 7.0. */
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/compat/php7/domain.php';
function domainCheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
$sources=json_decode(file_get_contents($root.'/compat/php7/core/sources.json'),true);
$count=0;
foreach($sources as $path=>$hash){
    domainCheck(hash_equals($hash,hash_file('sha256',$root.'/'.$path)),'Original source changed: '.$path);
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
