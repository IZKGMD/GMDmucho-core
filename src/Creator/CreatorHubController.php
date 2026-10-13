<?php
declare(strict_types=1);
namespace MuchoCore\Creator;
use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;
use RuntimeException;
final readonly class CreatorHubController {
 public function __construct(private AccountAuthenticator $auth,private CreatorHubService $hub){}
 public function handle(Request $r): Response {
  if($r->method!=='POST')return Response::json(['ok'=>false,'error'=>'Authenticated POST required.'],405);
  try{
   $id=$r->postInt('accountID');$this->auth->authenticate($id,$r->gdCredential());$a=$this->hub->gameActor($id);$request=$r->postInt('requestID');$revision=$r->postInt('revision');
   $data=match($r->postString('action')){
    'list'=>$this->hub->listing($a,$r->postString('view')?:'mine',$r->postInt('page',1)),
    'detail'=>$this->hub->detail($a,$request),'submit'=>$this->hub->submit($a,$r->postInt('levelID'),$r->postString('note')),
    'claim'=>$this->hub->claim($a,$request,$revision),'decide'=>$this->hub->decide($a,$request,$revision,$r->postString('state'),$r->postString('note')),
    'release'=>$this->hub->release($a,$request,$revision),
    'withdraw'=>$this->hub->withdraw($a,$request,$revision),default=>throw new RuntimeException('Unknown Creator Hub action.')};
   return Response::json(['ok'=>true,'schema_version'=>1,'data'=>$data]);
  }catch(RuntimeException $e){return Response::json(['ok'=>false,'error'=>$e->getMessage()],$e->getMessage()==='Unauthorized.'?401:400);}
  catch(Throwable $e){error_log('[CreatorHub] '.$e::class);return Response::json(['ok'=>false,'error'=>'Creator Hub is unavailable. Update MuchoCore and run migrations.'],503);}
 }
}
