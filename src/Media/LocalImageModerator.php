<?php
declare(strict_types=1);
namespace MuchoCore\Media;
use MuchoCore\Core\Environment;
use Throwable;

/** Fixed local IPC; never resolves a remote host or sends player data externally. */
final readonly class LocalImageModerator implements ImageModerator {
    public function __construct(private string $socket=''){}
    public function classify(string $image,string $text):array {
        $path=$this->socket!==''?$this->socket:(Environment::get('MUCHO_LOCAL_MODERATION_SOCKET','/run/mucho-moderation/moderation.sock')??'');
        if(!function_exists('curl_init')||!defined('CURLOPT_UNIX_SOCKET_PATH')||$path===''||$path[0]!=='/'||strlen($path)>100||str_contains($path,"\0"))return self::pending();
        $curl=curl_init('http://localhost/classify');if(!$curl)return self::pending();$reply='';
        try {
            curl_setopt_array($curl,[CURLOPT_UNIX_SOCKET_PATH=>$path,CURLOPT_PROXY=>'',CURLOPT_POST=>true,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['imageData'=>base64_encode($image),'text'=>$text],JSON_THROW_ON_ERROR),
                CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$reply):int {if(strlen($reply)+strlen($chunk)>4096)return 0;$reply.=$chunk;return strlen($chunk);}]);
            if(curl_exec($curl)===false||curl_getinfo($curl,CURLINFO_RESPONSE_CODE)!==200)return self::pending();
            return self::decision(json_decode($reply,true,8,JSON_THROW_ON_ERROR));
        }catch(Throwable){return self::pending();}finally {curl_close($curl);}
    }
    private static function pending():array {return ['state'=>'pending','reason'=>'local_unavailable'];}
    public static function decision(array $r):array {
        if(($r['schema_version']??null)!==1||($r['local']??null)!==true||!in_array($r['state']??null,['approved','pending','rejected'],true)||
           !is_string($r['reason']??null)||!preg_match('/^local_[a-z_]{1,64}$/D',$r['reason']))return ['state'=>'pending','reason'=>'local_invalid_response'];
        $allowed=['approved'=>['local_passed','local_text_rules_passed'],'rejected'=>['local_nsfw','local_text_rule'],'pending'=>['local_uncertain','local_unavailable','local_invalid_request','local_invalid_response']];
        if(!in_array($r['reason'],$allowed[$r['state']],true))return ['state'=>'pending','reason'=>'local_invalid_response'];
        return ['state'=>$r['state'],'reason'=>$r['reason']];
    }
}
