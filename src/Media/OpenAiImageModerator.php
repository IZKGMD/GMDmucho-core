<?php
declare(strict_types=1);
namespace MuchoCore\Media;
use MuchoCore\Core\Environment;
use Throwable;
final readonly class OpenAiImageModerator implements ImageModerator {
    public function __construct(private string $key=''){}
    public function classify(string $jpeg,string $text): array {
        $key=$this->key!==''?$this->key:(Environment::get('MUCHO_MODERATION_API_KEY','')??'');
        if($key===''||!function_exists('curl_init'))return ['state'=>'pending','reason'=>'moderator_unavailable'];
        // The endpoint is fixed: clients cannot send image URLs or exfiltrate credentials.
        $input=[['type'=>'text','text'=>$text!==''?$text:'Image attachment']];
        if($jpeg!=='')$input[]=['type'=>'image_url','image_url'=>['url'=>'data:image/jpeg;base64,'.base64_encode($jpeg)]];
        $curl=curl_init('https://api.openai.com/v1/moderations');
        if(!$curl)return ['state'=>'pending','reason'=>'moderator_unavailable'];
        $reply='';
        try {
            curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],
                CURLOPT_POSTFIELDS=>json_encode(['model'=>'omni-moderation-latest','input'=>$input],JSON_THROW_ON_ERROR),
                CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$reply):int{
                    if(strlen($reply)+strlen($chunk)>131072)return 0;$reply.=$chunk;return strlen($chunk);
                }]);
            if(curl_exec($curl)===false||curl_getinfo($curl,CURLINFO_RESPONSE_CODE)!==200)
                return ['state'=>'pending','reason'=>'moderator_unavailable'];
            return self::decision(json_decode($reply,true,32,JSON_THROW_ON_ERROR),$jpeg!=='');
        }catch(Throwable){return ['state'=>'pending','reason'=>'moderator_unavailable'];}
        finally{curl_close($curl);}
    }
    public static function decision(array $response,bool $image=true): array {
        $r=$response['results'][0]??null;
        if(!is_array($r)||!is_bool($r['flagged']??null)||!is_array($r['categories']??null))
            return ['state'=>'pending','reason'=>'moderator_invalid_response'];
        // A missing image classification must never be interpreted as a safe image.
        if($image)foreach(['sexual','violence','violence/graphic','self-harm','self-harm/intent','self-harm/instructions'] as $category){
            $types=$r['category_applied_input_types'][$category]??null;
            if(!is_bool($r['categories'][$category]??null)||!is_array($types)||!in_array('image',$types,true))
                return ['state'=>'pending','reason'=>'moderator_invalid_response'];
        }
        $flags=[];foreach($r['categories'] as $name=>$flag){
            if(!is_bool($flag))return ['state'=>'pending','reason'=>'moderator_invalid_response'];
            if($flag)$flags[]=$name;
        }
        return $r['flagged']||$flags?['state'=>'rejected','reason'=>substr('policy:'.implode(',',$flags),0,128)]
            :['state'=>'approved','reason'=>'automoderation_passed'];
    }
}
