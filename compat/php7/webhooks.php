<?php
/** Queued HTTPS webhook delivery for an explicitly configured owner endpoint. */
declare(strict_types=1);
function mucho7_webhook_url($url)
{
    $parts=is_string($url)?parse_url($url):false;
    if(!$parts||strlen($url)>2048||!filter_var($url,FILTER_VALIDATE_URL)||strtolower(isset($parts['scheme'])?$parts['scheme']:'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||(isset($parts['port'])&&(int)$parts['port']!==443))throw new RuntimeException('Use a public HTTPS webhook URL on port 443 without credentials or a fragment.');
    $host=isset($parts['host'])?strtolower($parts['host']):'';
    if(!preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D',$host)||strpos($host,'.')===false||preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid)$/D',$host))throw new RuntimeException('Use a public webhook hostname.');
    if(filter_var($host,FILTER_VALIDATE_IP)&&!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('Private webhook addresses are not allowed.');
    return $url;
}
function mucho7_shared_webhook_dispatch($settings,$event,$data,$requestId='',$transport=null)
{
    if(empty($settings['enable_webhooks']))return false;
    $url=mucho7_webhook_url(isset($settings['webhook_url'])?$settings['webhook_url']:'');
    if(!is_string($event)||!preg_match('/^[A-Za-z0-9_.-]{1,96}$/D',$event)||!is_array($data)||!is_string($requestId)||strlen($requestId)>128||preg_match('/[\x00-\x1f\x7f]/',$requestId))throw new RuntimeException('Invalid webhook job.');
    $body=json_encode(array('event'=>$event,'request_id'=>$requestId,'sent_at'=>gmdate('c'),'data'=>$data),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($body)||strlen($body)>65536)throw new RuntimeException('Webhook payload is invalid or too large.');
    $headers=array('Content-Type: application/json','Accept: application/json','User-Agent: MuchoCore-Webhook/1.0','X-MuchoCore-Event: '.$event);
    if($requestId!=='')$headers[]='X-MuchoCore-Request-Id: '.$requestId;
    if(!empty($settings['webhook_secret']))$headers[]='X-MuchoCore-Signature: sha256='.hash_hmac('sha256',$body,(string)$settings['webhook_secret']);
    // Test seam is callable-only and never read from configuration or HTTP.
    if($transport!==null)return (bool)$transport($url,$body,$headers);
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is required for queued webhook delivery.');
    $host=parse_url($url,PHP_URL_HOST);$addresses=gethostbynamel($host);
    if(!$addresses)throw new RuntimeException('Webhook DNS lookup failed.');
    foreach($addresses as $address)if(!filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('Webhook DNS resolved to a private address.');
    $curl=curl_init($url);$received=0;
    try{
        curl_setopt_array($curl,array(CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_RESOLVE=>array($host.':443:'.$addresses[0]),
            CURLOPT_WRITEFUNCTION=>function($handle,$bytes)use(&$received){$received+=strlen($bytes);return $received<=65536?strlen($bytes):0;}));
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
        return $ok!==false&&$status>=200&&$status<300;
    }finally{curl_close($curl);}
}
