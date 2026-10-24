<?php
/** Fixed-origin streaming replacement for the VPS /gd-content reverse proxy. */
declare(strict_types=1);
function mucho7_content_request($uri,$method,$server)
{
    if(!in_array($method,array('GET','HEAD'),true))throw new InvalidArgumentException('Read-only content route.');
    $path=parse_url($uri,PHP_URL_PATH);
    if(!is_string($path)||strpos($path,'/gd-content/')!==0)throw new InvalidArgumentException('Invalid content route.');
    $path=rawurldecode(substr($path,12));
    if($path===''||$path[0]==='/'||strlen($path)>600||!preg_match('~^[A-Za-z0-9_./ -]+$~D',$path)||preg_match('~(?:^|/)\.{1,2}(?:/|$)~',$path)||strpos($path,'//')!==false)throw new InvalidArgumentException('Invalid asset path.');
    $headers=array('Accept-Encoding: identity','User-Agent: MuchoCore-Shared/1');
    foreach(array('HTTP_RANGE'=>'Range','HTTP_IF_NONE_MATCH'=>'If-None-Match','HTTP_IF_MODIFIED_SINCE'=>'If-Modified-Since') as $key=>$name){
        if(!isset($server[$key]))continue;$value=$server[$key];
        if(!is_string($value)||strlen($value)>180||preg_match('/[\r\n\x00-\x1f]/',$value))throw new InvalidArgumentException('Invalid asset header.');
        if($key==='HTTP_RANGE'&&!preg_match('/^bytes=\d{1,12}-\d{0,12}$/D',$value))throw new InvalidArgumentException('Unsupported byte range.');
        $headers[]=$name.': '.$value;
    }
    return array('url'=>'https://geometrydashfiles.b-cdn.net/'.implode('/',array_map('rawurlencode',explode('/',$path))),'method'=>$method,'headers'=>$headers);
}
function mucho7_content_send($uri,$method,$server)
{
    try{$request=mucho7_content_request($uri,$method,$server);}catch(InvalidArgumentException $e){http_response_code(400);echo '-1';exit;}
    $context=stream_context_create(array('http'=>array('method'=>$request['method'],'header'=>implode("\r\n",$request['headers']),
        'timeout'=>15,'follow_location'=>0,'ignore_errors'=>true),'ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true)));
    $upstream=@fopen($request['url'],'rb',false,$context);
    if(!$upstream){http_response_code(502);echo '-1';exit;}
    $metadata=stream_get_meta_data($upstream);$status=502;$responseHeaders=array();
    foreach(isset($metadata['wrapper_data'])?$metadata['wrapper_data']:array() as $line){
        if(preg_match('~^HTTP/\S+ (\d{3})~',$line,$match)){$status=(int)$match[1];$responseHeaders=array();}
        elseif(strpos($line,':')!==false){list($name,$value)=explode(':',$line,2);$responseHeaders[strtolower(trim($name))]=trim($value);}
    }
    if(!in_array($status,array(200,206,304,404,416),true)){fclose($upstream);http_response_code(502);echo '-1';exit;}
    $limit=64*1024*1024;
    if(isset($responseHeaders['content-length'])&&(int)$responseHeaders['content-length']>$limit){fclose($upstream);http_response_code(413);echo '-1';exit;}
    while(ob_get_level()>0)ob_end_clean();
    http_response_code($status);header("Content-Security-Policy: default-src 'none'");
    $type=isset($responseHeaders['content-type'])?$responseHeaders['content-type']:'application/octet-stream';
    if(!preg_match('~^(?:audio/|image/|application/(?:octet-stream|zip)|text/plain)(?:[A-Za-z0-9.+-]*)(?:;|$)~i',$type))$type='application/octet-stream';
    header('Content-Type: '.$type);header('Cache-Control: public, max-age=300');
    foreach(array('content-length','content-range','accept-ranges','etag','last-modified') as $name)if(isset($responseHeaders[$name])&&!preg_match('/[\r\n]/',$responseHeaders[$name]))header($name.': '.$responseHeaders[$name]);
    if($method!=='HEAD'&&$status!==304){
        $sent=0;
        while(!feof($upstream)&&$sent<$limit&&!connection_aborted()){
            $chunk=fread($upstream,min(65536,$limit-$sent));if($chunk===false||$chunk==='')break;
            $sent+=strlen($chunk);echo $chunk;flush();
        }
    }
    fclose($upstream);exit;
}
