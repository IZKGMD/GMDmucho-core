<?php
declare(strict_types=1);
namespace MuchoCore\Media;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use PDOException;
use RuntimeException;
use Throwable;
final readonly class MediaController {
    public function __construct(private MediaService $service){}
    private static function json(array $data,int $status=200):Response {
        $r=Response::json($data,$status);return new Response($r->body,$r->status,$r->contentType,['Cache-Control'=>'no-store']);
    }
    public function handle(Request $r): Response {
        if($r->method!=='POST')return self::json(['ok'=>false,'error'=>'Use authenticated POST.'],405);
        try {
            $id=$r->postInt('accountID');$credential=$r->gdCredential();$action=$r->postString('action');
            if($action==='image')return new Response($this->service->image($id,$credential,$r->postInt('imageID')),200,'image/jpeg',
                ['Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff']);
            $data=match($action){
                'config'=>$this->service->configuration(),
                'upload'=>$this->service->upload($id,$credential,$r->postString('scope'),$r->postInt('targetID'),$r->postString('imageData'),$r->postString('caption'),$r->postString('subject')),
                'attachments'=>$this->service->attachments($id,$credential,$r->postInt('messageID')),
                'mine'=>$this->service->mine($id,$credential),
                'remove'=>$this->service->remove($id,$credential,$r->postInt('imageID')),
                'report'=>$this->service->report($id,$credential,$r->postInt('imageID'),$r->postString('reason')?:'other'),
                'chat'=>$this->service->chat($id,$credential,$r->postInt('roomID'),$r->postInt('before')),
                'chat-send'=>$this->service->sendChat($id,$credential,$r->postInt('roomID'),$r->postString('text')),
                default=>throw new RuntimeException('Unknown media action.')};
            return self::json(['ok'=>true,'schema_version'=>1,'data'=>$data]);
        }catch(PDOException){return self::json(['ok'=>false,'error'=>'Update MuchoCore and run migrations for images and room chat.'],503);}
        catch(RuntimeException $e){return self::json(['ok'=>false,'error'=>$e->getMessage()],$e->getMessage()==='Unauthorized.'?401:400);}
        catch(Throwable $e){error_log('[Media] '.$e::class);return self::json(['ok'=>false,'error'=>'Media service is unavailable.'],503);}
    }
}
