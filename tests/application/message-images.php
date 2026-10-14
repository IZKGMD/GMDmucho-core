<?php
declare(strict_types=1);
use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Http\Request;
use MuchoCore\Media\ImageModerator;
use MuchoCore\Media\MediaController;
use MuchoCore\Media\MediaService;
use MuchoCore\Media\MessageImage;
use MuchoCore\Media\OpenAiImageModerator;
spl_autoload_register(static function(string $class):void {if(str_starts_with($class,'MuchoCore\\'))require dirname(__DIR__,2).'/src/'.str_replace('\\','/',substr($class,10)).'.php';});
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function refused(callable $call,string $message):void {try{$call();}catch(RuntimeException){return;}throw new RuntimeException($message);}
final class RecordingModerator implements ImageModerator {
 public int $calls=0;public array $decision=['state'=>'approved','reason'=>'test_passed'];public $before=null;
 public function classify(string $jpeg,string $text):array {$this->calls++;if($this->before)($this->before)();return $this->decision;}
}
$host=getenv('TEST_DB_HOST')?:'127.0.0.1';$port=getenv('TEST_DB_PORT')?:'3306';
$root=new PDO("mysql:host=$host;port=$port",'root',getenv('TEST_DB_ROOT_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='mc_media_'.bin2hex(random_bytes(5));$root->exec("CREATE DATABASE $name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
 $db=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",'root',getenv('TEST_DB_ROOT_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $db->exec('CREATE TABLE accounts(account_id BIGINT UNSIGNED PRIMARY KEY,username VARCHAR(32),password_hash VARCHAR(255),gjp2_hash VARCHAR(255),is_active INT DEFAULT 1,is_banned INT DEFAULT 0,messages_state INT DEFAULT 0) ENGINE=InnoDB');
 $db->exec('CREATE TABLE profiles(user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,account_id BIGINT UNSIGNED UNIQUE) ENGINE=InnoDB');
 $db->exec('CREATE TABLE friends(account_id BIGINT UNSIGNED,friend_account_id BIGINT UNSIGNED,PRIMARY KEY(account_id,friend_account_id)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE blocks(account_id BIGINT UNSIGNED,blocked_account_id BIGINT UNSIGNED,PRIMARY KEY(account_id,blocked_account_id)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE server_settings(setting_key VARCHAR(128) PRIMARY KEY,setting_value TEXT) ENGINE=InnoDB');
 $db->exec('CREATE TABLE messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,account_id BIGINT UNSIGNED,to_account_id BIGINT UNSIGNED,subject VARCHAR(255),body TEXT,is_sender_deleted INT DEFAULT 0,is_receiver_deleted INT DEFAULT 0) ENGINE=InnoDB');
 foreach(['20261013_001_social_live.php','20261014_001_message_media.php'] as $migration)foreach(require dirname(__DIR__,2).'/database/migrations/'.$migration as $sql)$db->exec($sql);
 $token=str_repeat('a',40);$hash=password_hash($token,PASSWORD_BCRYPT,['cost'=>4]);$insert=$db->prepare('INSERT INTO accounts(account_id,username,password_hash,gjp2_hash) VALUES(?,?,?,?)');
 for($i=1;$i<=4;$i++){$insert->execute([$i,'User'.$i,'',$hash]);$db->exec("INSERT INTO profiles(account_id) VALUES($i)");}
 $moderator=new RecordingModerator;$auth=new AccountAuthenticator($db);$service=new MediaService($db,$auth,$moderator);$controller=new MediaController($service);
 $setting=static function(string $key,string $value)use($db):void {$q=$db->prepare('UPDATE server_settings SET setting_value=? WHERE setting_key=?');$q->execute([$value,$key]);};
 $call=static function(int $id,string $action,array $args=[])use($controller,$token){return $controller->handle(new Request('POST','/api/media',[],['accountID'=>(string)$id,'gameVersion'=>'22','gjp2'=>$token,'action'=>$action]+$args,[]));};
 $png=static function(int $w=200,int $h=100,int $color=42):string {$im=imagecreatetruecolor($w,$h);imagefill($im,0,0,imagecolorallocate($im,$color,80,130));ob_start();imagepng($im);$s=(string)ob_get_clean();imagedestroy($im);return $s;};
 $source=$png();$encoded=base64_encode($source);
 $upload=static fn(int $id=1,string $scope='message',int $target=2,string $bytes='')=>$service->upload($id,$token,$scope,$target,$bytes?:$encoded,'hello 😀😀','Photo');
 check(!$service->configuration()['images_enabled'],'Images must default off');
 refused(fn()=>$upload(),'Disabled images accepted');check($moderator->calls===0,'Disabled images sent to AI');
 check($call(0,'upload',['scope'=>'message','targetID'=>'2','imageData'=>$encoded])->status===401,'Anonymous image accepted');
 $setting('media.images.enabled','1');$pending=$upload();check($pending['state']==='pending','Manual upload published');
 refused(fn()=>$service->image(2,$token,(int)$pending['id']),'Pending file leaked');check((int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn()===0,'Pending DM created');
 $service->review((int)$pending['id'],'approved','admin_approved');$service->review((int)$pending['id'],'approved','admin_approved');
 check((int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn()===1,'Approval duplicated DM');
 $message=(int)$db->query('SELECT id FROM messages LIMIT 1')->fetchColumn();$image=(int)$pending['id'];
 check(count($service->attachments(2,$token,$message)['images'])===1,'Recipient attachment missing');
 refused(fn()=>$service->attachments(3,$token,$message),'Attachment leaked to outsider');
 $response=$call(2,'image',['imageID'=>(string)$image]);check($response->status===200&&$response->contentType==='image/jpeg'&&($response->headers['Cache-Control']??'')==='no-store','Private JPEG transport/cache headers missing');
 check($response->forPluginEvent()->body==='','Private image exposed to plugins');
 check(getimagesizefromstring($response->body)[2]===IMAGETYPE_JPEG,'Upload not sanitized to JPEG');
 $db->exec('UPDATE messages SET is_receiver_deleted=1');refused(fn()=>$service->image(2,$token,$image),'Deleted DM image accessible');check($service->image(1,$token,$image)!=='','Receiver deletion removed sender access');$db->exec('UPDATE messages SET is_receiver_deleted=0');
 $db->exec('INSERT INTO blocks VALUES(2,1)');refused(fn()=>$upload(),'Blocked DM upload accepted');refused(fn()=>$service->image(1,$token,$image),'Blocked DM image accessible');$db->exec('DELETE FROM blocks');
 $db->exec('UPDATE accounts SET messages_state=1 WHERE account_id=2');refused(fn()=>$upload(),'Friends-only upload accepted');$db->exec('INSERT INTO friends VALUES(1,2),(2,1)');
 $setting('media.moderation.mode','openai');$safe=$upload();check($safe['state']==='approved'&&$moderator->calls===1,'Automatic approval failed');
 $service->report(2,$token,$image,'nsfw');refused(fn()=>$service->image(1,$token,$image),'Reported image not quarantined');
 refused(fn()=>$service->image(2,$token,(int)$safe['id']),'Reported duplicate hash still accessible');
 $before=$moderator->calls;refused(fn()=>$upload(),'Reported raw hash accepted');check($moderator->calls===$before,'Reported material sent to external AI');
 $canonical=base64_encode($response->body);refused(fn()=>$upload(1,'message',2,$canonical),'Reported normalized hash accepted');check($moderator->calls===$before,'Normalized blocked file sent to AI');
 $service->review($image,'approved','admin_approved');check((int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn()===2,'False report reapproval duplicated DM');
 $moderator->decision=['state'=>'pending','reason'=>'moderator_unavailable'];$hold=$upload(1,'message',2,base64_encode($png(201,100,43)));check($hold['state']==='pending','Provider failure published image');
 $db->exec('UPDATE accounts SET messages_state=2 WHERE account_id=2');$service->review((int)$hold['id'],'approved','admin_approved');check($db->query('SELECT state FROM mucho_message_media WHERE id='.(int)$hold['id'])->fetchColumn()==='rejected','Revoked recipient permission ignored');$db->exec('UPDATE accounts SET messages_state=0 WHERE account_id=2');
 $moderator->decision=['state'=>'rejected','reason'=>'policy:sexual'];$reject=$upload(1,'message',2,base64_encode($png(202,100,44)));check($reject['state']==='rejected','AI rejection ignored');check($db->query('SELECT image_data FROM mucho_message_media WHERE id='.(int)$reject['id'])->fetchColumn()===null,'Rejected bytes retained');
 refused(fn()=>$service->review((int)$reject['id'],'approved','admin_approved'),'Rejected data restored');
 $setting('media.moderation.mode','off');$db->exec('INSERT INTO mucho_social_rooms(id,owner_id,level_id,expires_at) VALUES(10,1,100,UTC_TIMESTAMP()+INTERVAL 1 HOUR)');$db->exec('INSERT INTO mucho_social_members(room_id,account_id) VALUES(10,1),(10,2)');
 refused(fn()=>$service->chat(3,$token,10),'Room chat leaked');refused(fn()=>$upload(3,'room',10),'Outsider room upload accepted');
 $roomImage=$upload(1,'room',10,base64_encode($png(204,100,45)));$chat=$service->chat(2,$token,10);check(count($chat['messages'])===1&&(int)$chat['messages'][0]['media_id']===(int)$roomImage['id'],'Room image missing');
 $service->report(2,$token,(int)$roomImage['id'],'other');check($service->chat(2,$token,10)['messages'][0]['media_id']===null,'Reported image remained in chat');$service->review((int)$roomImage['id'],'approved','admin_approved');check(count($service->chat(2,$token,10)['messages'])===1,'Reapproval duplicated chat row');
 $setting('media.images.enabled','0');check(count($service->sendChat(2,$token,10,'text works 😀😀')['messages'])===2,'Text chat depends on images option');refused(fn()=>$service->image(2,$token,(int)$roomImage['id']),'Disabled images still served');
 $setting('media.images.enabled','1');$service->remove(1,$token,(int)$roomImage['id']);refused(fn()=>$service->remove(2,$token,(int)$safe['id']),'Nonowner removed file');
 $db->exec('DELETE FROM mucho_social_members WHERE room_id=10 AND account_id=2');refused(fn()=>$service->chat(2,$token,10),'Former member retained chat access');
 $db->exec('UPDATE mucho_social_rooms SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=10');refused(fn()=>$service->sendChat(1,$token,10,'expired'),'Expired room accepted text');
 $setting('media.moderation.mode','openai');$moderator->decision=['state'=>'approved','reason'=>'test_passed'];$moderator->before=static fn()=>$setting('media.moderation.mode','manual');$changed=$upload(4,'message',2,base64_encode($png(205,100,46)));check($changed['state']==='pending','Changed moderation policy still published image');$moderator->before=null;
 $large=MessageImage::render($png(1600,800));check($large['width']===1280&&$large['height']===640&&strlen($large['bytes'])<=MessageImage::MAX_OUTPUT,'Resize/byte cap wrong');
 refused(fn()=>MessageImage::render('<svg><script>alert(1)</script></svg>'),'SVG accepted');refused(fn()=>MessageImage::decode($encoded.' '),'Noncanonical base64 accepted');refused(fn()=>MessageImage::render($png(8192,16)),'Extreme aspect ratio accepted');
 $sourceHash=hash('sha256',$source);$service->review((int)$safe['id'],'rejected','admin_rejected',true);refused(fn()=>$upload(),'Admin hash block ignored');refused(fn()=>$service->image(2,$token,$image),'Admin block did not hide existing matching image');check($db->query("SELECT COUNT(*) FROM mucho_media_blocklist WHERE sha256='$sourceHash'")->fetchColumn()>0,'Hash block missing');
 $db->exec("INSERT INTO mucho_message_media(account_id,scope,target_id,source_hash,image_hash,width,height,image_data,state,created_at) SELECT 4,'message',2,source_hash,image_hash,width,height,image_data,'pending',UTC_TIMESTAMP() FROM mucho_message_media WHERE id=".(int)$changed['id']);
 for($i=0;$i<18;$i++)$db->exec("INSERT INTO mucho_message_media(account_id,scope,target_id,source_hash,image_hash,width,height,image_data,state,created_at) SELECT 4,'message',2,source_hash,image_hash,width,height,image_data,'pending',UTC_TIMESTAMP() FROM mucho_message_media WHERE id=".(int)$changed['id']);
 $before=$moderator->calls;refused(fn()=>$upload(4,'message',2),'Hourly quota ignored');check($moderator->calls===$before,'Rate limited image sent to AI');
 $db->exec('UPDATE mucho_message_media SET created_at=UTC_TIMESTAMP()-INTERVAL 2 HOUR WHERE account_id=4');
 for($i=0;$i<30;$i++)$db->exec("INSERT INTO mucho_message_media(account_id,scope,target_id,source_hash,image_hash,width,height,image_data,state,created_at) SELECT 4,'message',2,source_hash,image_hash,width,height,image_data,'pending',UTC_TIMESTAMP()-INTERVAL 2 HOUR FROM mucho_message_media WHERE id=".(int)$changed['id']);
 refused(fn()=>$upload(4,'message',2),'Storage quota ignored');
 check(($call(2,'mine')->headers['Cache-Control']??'')==='no-store','Private JSON response cacheable');
 echo "Media opt-in, moderation, reports, JPEG limits, DM privacy and room chat: OK\n";
}finally{$root->exec("DROP DATABASE $name");}
