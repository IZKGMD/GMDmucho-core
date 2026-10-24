<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/compat/php7/content-proxy.php';
$request=mucho7_content_request('/gd-content/music/123.mp3?cache=1','GET',array('HTTP_RANGE'=>'bytes=0-1023'));
if($request['url']!=='https://geometrydashfiles.b-cdn.net/music/123.mp3'||!in_array('Range: bytes=0-1023',$request['headers'],true))throw new RuntimeException('Asset/range mapping failed.');
foreach(array('/gd-content/../config/key','/gd-content/%2e%2e/secret','/gd-content/%252e%252e/secret','/gd-content//attacker.invalid/x','/gd-content/https://attacker.invalid/x') as $uri){
    $rejected=false;try{mucho7_content_request($uri,'GET',array());}catch(InvalidArgumentException $e){$rejected=true;}
    if(!$rejected)throw new RuntimeException('Unsafe CDN path accepted.');
}
foreach(array('bytes=0-1,5-6',"bytes=0-1\r\nHost: attacker.invalid") as $range){
    $rejected=false;try{mucho7_content_request('/gd-content/music/123.mp3','GET',array('HTTP_RANGE'=>$range));}catch(InvalidArgumentException $e){$rejected=true;}
    if(!$rejected)throw new RuntimeException('Unsafe range accepted.');
}
echo "PHP7_FIXED_CDN_PATH_RANGE_OK\n";
