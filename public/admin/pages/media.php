<?php
declare(strict_types=1);
use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Media\MediaService;
function mediaAdminService(PDO $db):MediaService {requirePermission('messages.manage');return new MediaService($db,new AccountAuthenticator($db));}
function saveMediaSettings(PDO $db):void {
 if(!isset($_POST['media_settings_present']))return;
 requirePermission('settings.manage');
 $mode=(string)($_POST['media_moderation']??'manual');
 if(!in_array($mode,['off','manual','openai'],true))throw new RuntimeException('Invalid image moderation mode.');
 $q=$db->prepare('INSERT INTO server_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
 $db->beginTransaction();try {
  $q->execute(['media.images.enabled',isset($_POST['media_images'])?'1':'0']);$q->execute(['media.moderation.mode',$mode]);$db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function handleMediaAdminAction(PDO $db):void {
 $service=mediaAdminService($db);$id=(int)($_POST['image_id']??0);$state=(string)($_POST['state']??'');
 try {
  if(!in_array($state,['approved','rejected'],true))throw new RuntimeException('Choose approve or reject.');
  $service->review($id,$state,$state==='approved'?'admin_approved':'admin_rejected',$state==='rejected'&&isset($_POST['block_hash']));
  audit($db,'media.review',(string)$id,['image_id'=>$id,'state'=>$state]);flash('Image review saved.','ok');
 }catch(Throwable $e){flash($e instanceof PDOException?'Image storage unavailable. Run migrations.':$e->getMessage(),'err');}
 header('Location: /admin/?page=media');exit;
}
function mediaAdminPreview(PDO $db):void {
 requirePermission('messages.manage');
 if(!is_string($_GET['token']??null)||!hash_equals(csrf(),$_GET['token'])){http_response_code(403);exit;}
 $q=$db->prepare('SELECT image_data FROM mucho_message_media WHERE id=? AND image_data IS NOT NULL');$q->execute([(int)($_GET['media_preview']??0)]);$bytes=$q->fetchColumn();
 if(!is_string($bytes)){http_response_code(404);exit;}
 header('Content-Type: image/jpeg');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');echo $bytes;exit;
}
function renderMediaPage(PDO $db):void {
 $service=mediaAdminService($db);$page=max(1,min(100000,(int)($_GET['p']??1)));$offset=($page-1)*25;
 echo '<section class="card"><h2>Images and reports</h2><p class="muted">Pending files are hidden from players. Review suspicious content here; reported files never go to an external classifier again. Image sending and automatic moderation are optional in Settings.</p><a class="btn" href="/admin/?page=settings">Settings</a></section>';
 try {
  $rows=$db->query("SELECT m.id,m.account_id,m.scope,m.target_id,m.subject,m.caption,m.state,m.reason,m.created_at,(SELECT COUNT(*) FROM mucho_media_reports r WHERE r.media_id=m.id) reports FROM mucho_message_media m WHERE m.state='pending' AND m.image_data IS NOT NULL ORDER BY m.id ASC LIMIT 26 OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
  $more=count($rows)>25;$rows=array_slice($rows,0,25);
  foreach($rows as $r){$id=(int)$r['id'];
   echo '<section class="card"><h3>Image #'.$id.'</h3><p class="muted">Account #'.(int)$r['account_id'].' · '.h($r['scope']).' #'.(int)$r['target_id'].' · '.h($r['created_at']).' · '.h($r['reason']).' · reports '.(int)$r['reports'].'</p><b>'.h($r['subject']).'</b><p style="white-space:pre-wrap">'.h($r['caption']).'</p>';
   echo '<a class="btn gray" target="_blank" rel="noreferrer noopener" href="/admin/?media_preview='.$id.'&token='.h(csrf()).'">View protected image</a>';
   echo '<form method="post" action="/admin/?page=media"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="media-review"><input type="hidden" name="image_id" value="'.$id.'"><p><label><input type="checkbox" name="block_hash" checked> Block matching uploads when rejecting</label></p><button name="state" value="approved">Approve</button> <button class="danger" name="state" value="rejected">Reject and remove</button></form></section>';
  }
  if(!$rows)echo '<section class="card">No pending images.</section>';
  if($page>1)echo '<a class="btn gray" href="/admin/?page=media&p='.($page-1).'">Previous</a> ';
  if($more)echo '<a class="btn" href="/admin/?page=media&p='.($page+1).'">Next</a>';
 }catch(PDOException){echo '<section class="card">Image storage unavailable. Update the core and run migrations.</section>';}
}
