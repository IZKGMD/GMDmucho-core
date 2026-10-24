<?php
/** Runtime helpers for code emitted by compile-php7-domain.php. PHP 7.0+. */
declare(strict_types=1);
if (!defined('JSON_THROW_ON_ERROR')) define('JSON_THROW_ON_ERROR', 4194304);
if (!defined('JSON_INVALID_UTF8_SUBSTITUTE')) define('JSON_INVALID_UTF8_SUBSTITUTE', 2097152);
if (!defined('OPENSSL_RAW_DATA')) define('OPENSSL_RAW_DATA', 1);
if (!class_exists('JsonException', false)) { class JsonException extends Exception {} }
if (!class_exists('UnhandledMatchError', false)) { class UnhandledMatchError extends Error {} }
/** PHP 7.0 can import/verify EC keys but has no openssl_get_curve_names(). */
function mucho7_passkey_curves()
{
    if(function_exists('openssl_get_curve_names'))return openssl_get_curve_names();
    static $curves=null;if($curves!==null)return $curves;$curves=array();
    if(!function_exists('openssl_pkey_get_public')||!defined('OPENSSL_KEYTYPE_EC'))return $curves;
    // Public-only P-256 capability probe. No private key is embedded or used.
    $pem="-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEgvv5MUZsd0rca+fmYn4wtrKhm9Hq\n2cCx9SnBMACUC4EGFHCWff4pZ9zhwy7DkQZli7gCjwwAO67zWzPdtbeBHw==\n-----END PUBLIC KEY-----\n";
    $key=@openssl_pkey_get_public($pem);if($key===false)return $curves;
    $details=openssl_pkey_get_details($key);
    if(is_array($details)&&$details['type']===OPENSSL_KEYTYPE_EC&&isset($details['ec']['curve_name'])&&$details['ec']['curve_name']==='prime256v1')$curves[]='prime256v1';
    return $curves;
}
if (!function_exists('str_contains')) {
    function str_contains($value, $needle) { return $needle==='' || strpos($value,$needle)!==false; }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($value, $needle) { return $needle==='' || strncmp($value,$needle,strlen($needle))===0; }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($value, $needle) { return $needle==='' || substr($value,-strlen($needle))===$needle; }
}
if (!function_exists('array_is_list')) {
    function array_is_list($value) { $index=0;foreach($value as $key=>$_){if($key!==$index++)return false;}return true; }
}
if (!function_exists('array_key_first')) {
    function array_key_first($value) { foreach($value as $key=>$_)return $key;return null; }
}
if (!function_exists('array_key_last')) {
    function array_key_last($value) { if(!$value)return null;end($value);return key($value); }
}
/** Replacement characters follow JSON_INVALID_UTF8_SUBSTITUTE on old JSON. */
function mucho7_json_utf8($value)
{
    if(is_string($value)) {
        if(preg_match('//u',$value)===1)return $value;
        // Consume only valid Unicode scalar sequences; each invalid byte is
        // replaced, rather than silently dropping private or protocol text.
        return preg_replace_callback('/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|./s',function($match){return preg_match('//u',$match[0])===1?$match[0]:"\xEF\xBF\xBD";},$value);
    }
    if(is_array($value)) {
        $result=array();foreach($value as $key=>$item)$result[is_string($key)?mucho7_json_utf8($key):$key]=mucho7_json_utf8($item);return $result;
    }
    return $value;
}
function mucho7_json_encode($value, $flags=0, $depth=512)
{
    if(PHP_VERSION_ID>=70300)return json_encode($value,$flags,$depth);
    $nativeFlags=$flags & ~JSON_THROW_ON_ERROR;
    if(PHP_VERSION_ID<70200 && ($flags & JSON_INVALID_UTF8_SUBSTITUTE)) {
        $nativeFlags &= ~JSON_INVALID_UTF8_SUBSTITUTE;
        // Only retry after a native UTF-8 failure, preserving native cycle
        // detection and JsonSerializable behavior for other input values.
        $result=json_encode($value,$nativeFlags,$depth);
        if(json_last_error()===JSON_ERROR_UTF8)$value=mucho7_json_utf8($value);
    }
    $result=json_encode($value,$nativeFlags,$depth);
    if(($flags & JSON_THROW_ON_ERROR) && json_last_error()!==JSON_ERROR_NONE)throw new JsonException(json_last_error_msg(),json_last_error());
    return $result;
}
function mucho7_json_decode($value, $associative=false, $depth=512, $flags=0)
{
    if(PHP_VERSION_ID>=70300)return json_decode($value,$associative,$depth,$flags);
    if($depth<1)throw new InvalidArgumentException('JSON depth must be positive.');
    if(PHP_VERSION_ID<70200 && ($flags & JSON_INVALID_UTF8_SUBSTITUTE))$value=mucho7_json_utf8($value);
    $result=json_decode($value,$associative,$depth,$flags & ~JSON_THROW_ON_ERROR & ~JSON_INVALID_UTF8_SUBSTITUTE);
    if(($flags & JSON_THROW_ON_ERROR) && json_last_error()!==JSON_ERROR_NONE)throw new JsonException(json_last_error_msg(),json_last_error());
    return $result;
}
/** PHP 7.4's argument-array subprocess API, with shell-safe argv on PHP 7.0. */
function mucho7_proc_open($command,$descriptors,&$pipes,$directory=null,$environment=null,$options=array())
{
    if(is_array($command) && PHP_VERSION_ID<70400){
        $arguments=array();foreach($command as $argument){
            if(!is_scalar($argument)||strpos((string)$argument,"\0")!==false)throw new InvalidArgumentException('Invalid process argument.');
            $arguments[]=escapeshellarg((string)$argument);
        }
        $command=implode(' ',$arguments);
    }
    return proc_open($command,$descriptors,$pipes,$directory,$environment,$options);
}
function mucho7_callable_closure($callable)
{
    if($callable instanceof Closure)return $callable;
    if(!is_callable($callable))throw new InvalidArgumentException('Invalid callable.');
    if(PHP_VERSION_ID>=70100)return Closure::fromCallable($callable);
    return function(...$arguments)use($callable){return call_user_func_array($callable,$arguments);};
}
/** Keep the original AES-GCM record format on PHP7.0's pre-AEAD OpenSSL API. */
function mucho7_openssl_encrypt($plain,$method,$key,$options=0,$nonce='',&$tag=null,$aad='',$tagLength=16,$forceFallback=false)
{
    if(!$forceFallback && PHP_VERSION_ID>=70100)return openssl_encrypt($plain,$method,$key,$options,$nonce,$tag,$aad,$tagLength);
    if(!preg_match('/^aes-(128|192|256)-gcm$/i',$method,$match))return openssl_encrypt($plain,$method,$key,$options,$nonce);
    if(strlen($key)!==(int)$match[1]/8 || strlen($nonce)!==12 || $tagLength!==16)throw new InvalidArgumentException('Invalid AES-GCM parameters.');
    $cipher=new \phpseclib3\Crypt\AES('gcm');$cipher->setPreferredEngine('OpenSSL');
    $cipher->setKeyLength((int)$match[1]);$cipher->setKey($key);$cipher->setNonce($nonce);$cipher->setAAD($aad);
    $encrypted=$cipher->encrypt($plain);$tag=$cipher->getTag(16);
    return ($options & OPENSSL_RAW_DATA)?$encrypted:base64_encode($encrypted);
}
function mucho7_openssl_decrypt($encrypted,$method,$key,$options=0,$nonce='',$tag='',$aad='',$forceFallback=false)
{
    if(!$forceFallback && PHP_VERSION_ID>=70100)return openssl_decrypt($encrypted,$method,$key,$options,$nonce,$tag,$aad);
    if(!preg_match('/^aes-(128|192|256)-gcm$/i',$method,$match))return openssl_decrypt($encrypted,$method,$key,$options,$nonce);
    if(strlen($key)!==(int)$match[1]/8 || strlen($nonce)!==12 || strlen($tag)!==16)return false;
    if(!($options & OPENSSL_RAW_DATA)){$encrypted=base64_decode($encrypted,true);if($encrypted===false)return false;}
    try{
        $cipher=new \phpseclib3\Crypt\AES('gcm');$cipher->setPreferredEngine('OpenSSL');
        $cipher->setKeyLength((int)$match[1]);$cipher->setKey($key);$cipher->setNonce($nonce);$cipher->setAAD($aad);$cipher->setTag($tag);
        return $cipher->decrypt($encrypted);
    }catch(\phpseclib3\Exception\BadDecryptionException $error){return false;}
}

// PHP 7.0 lacks hrtime(); profiling uses a nondecreasing wall-clock fallback.
if(!function_exists('hrtime')){
    function hrtime($asNumber=false){static $last=0;$now=(int)round(microtime(true)*1000000000);$last=max($last,$now);return $asNumber?$last:array(intdiv($last,1000000000),$last%1000000000);}
}
