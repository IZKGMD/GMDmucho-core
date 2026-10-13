<?php
declare(strict_types=1);
namespace MuchoCore\Creator;
use PDO;
use RuntimeException;
use Throwable;
final readonly class CreatorHubService
{
 public function __construct(private PDO $db){}
 private function query(string $sql,array $args=[]): \PDOStatement {$s=$this->db->prepare($sql);$s->execute($args);return $s;}
 public function gameActor(int $id): array {
  $a=$this->query("SELECT a.account_id,a.username,COALESCE(r.code,'user') role FROM accounts a LEFT JOIN roles r ON r.id=a.role_id WHERE a.account_id=? AND a.is_active=1 AND a.is_banned=0",[$id])->fetch(PDO::FETCH_ASSOC);
  if(!$a)throw new RuntimeException('Unauthorized.');
  return ['kind'=>'game','id'=>$id,'name'=>(string)$a['username'],'review'=>in_array($a['role'],['moderator','elder_moderator','owner'],true)];
 }
 public function adminActor(int $id,string $name): array {
  if($id<=0)throw new RuntimeException('Unauthorized.');
  return ['kind'=>'admin','id'=>$id,'name'=>$name,'review'=>true];
 }
 private function reviewer(array $a): void {if(!$a['review'])throw new RuntimeException('Moderator permission required.');}
 private function note(string $value,bool $required=false): string {
  $value=trim($value);
  if(strlen($value)>2000||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value)||($required&&strlen($value)<8))throw new RuntimeException('Enter meaningful feedback (8 to 2000 UTF-8 bytes).');
  return $value;
 }
 private function transaction(callable $fn): mixed {
  if($this->db->inTransaction())throw new \LogicException('Creator Hub requires its own transaction.');
  $this->db->beginTransaction();try{$value=$fn();$this->db->commit();return $value;}catch(Throwable $e){$this->db->rollBack();throw $e;}
 }
 private function event(array $a,array $r,string $action,string $note,array $metadata=[]): void {
  $this->query('INSERT INTO mucho_creator_events(request_id,cycle,actor_kind,actor_id,actor_name,action,note,metadata_json) VALUES(?,?,?,?,?,?,?,?)',[$r['id'],$r['cycle'],$a['kind'],$a['id'],$a['name'],$action,$note,json_encode($metadata,JSON_THROW_ON_ERROR)]);
 }
 private function lock(int $id): array {
  // Match the submission lock order: level first, then request.
  $level=$this->query('SELECT level_id FROM mucho_creator_requests WHERE id=?',[$id])->fetchColumn();
  if(!$level)throw new RuntimeException('Request not found.');
  $this->query('SELECT level_id FROM levels WHERE level_id=? FOR UPDATE',[$level]);
  $r=$this->query('SELECT * FROM mucho_creator_requests WHERE id=? FOR UPDATE',[$id])->fetch(PDO::FETCH_ASSOC);
  if(!$r)throw new RuntimeException('Request not found.');return $r;
 }
 private function revision(array $r,int $version): void {if((int)$r['revision']!==$version)throw new RuntimeException('Request changed. Refresh before continuing.');}
 private function owned(array $a,array $r): void {if($a['kind']!=='game'||$a['id']!==(int)$r['account_id'])throw new RuntimeException('This request belongs to another creator.');}
 private function activeLevel(int $id): array {
  $l=$this->query('SELECT level_id,account_id,name,level_version,stars,rate_tier,SHA2(level_data,256) content_hash,is_deleted,is_unlisted,unlisted2 FROM levels WHERE level_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
  if(!$l||(int)$l['is_deleted']!==0||(int)$l['is_unlisted']!==0||(int)$l['unlisted2']!==0)throw new RuntimeException('Choose a public, published level.');return $l;
 }
 public function submit(array $a,int $level,string $note): array {
  if($a['kind']!=='game')throw new RuntimeException('Only a creator can submit a level.');$note=$this->note($note);
  $id=$this->transaction(function()use($a,$level,$note):int {
   $this->query('SELECT account_id FROM accounts WHERE account_id=? FOR UPDATE',[$a['id']]);
   $this->query('SELECT level_id FROM levels WHERE level_id=? FOR UPDATE',[$level]);$l=$this->activeLevel($level);
   if((int)$l['account_id']!==$a['id'])throw new RuntimeException('Submit only your own level.');
   if((int)$l['stars']>0)throw new RuntimeException('This level is already rated.');
   $r=$this->query('SELECT * FROM mucho_creator_requests WHERE level_id=? FOR UPDATE',[$level])->fetch(PDO::FETCH_ASSOC);
   $same=$r&&(string)$r['content_hash']===(string)$l['content_hash']&&(int)$r['level_version']===(int)$l['level_version'];
   if($r&&in_array($r['state'],['submitted','in_review'],true)&&$same)return (int)$r['id'];
   if($r){
    if((int)$r['account_id']!==$a['id'])throw new RuntimeException('Level ownership changed. Contact moderation.');
    if($r['state']==='changes_requested'&&$same)throw new RuntimeException('Update the level before resubmitting it.');
    if(in_array($r['state'],['approved','rejected','withdrawn'],true)&&strtotime($r['submitted_at'].' UTC')>time()-86400)throw new RuntimeException('Wait 24 hours before resubmitting this request.');
   }
   $active=(int)$this->query("SELECT COUNT(*) FROM mucho_creator_requests WHERE account_id=? AND state IN ('submitted','in_review')",[$a['id']])->fetchColumn();
   if($active>=3&&(!$r||!in_array($r['state'],['submitted','in_review'],true)))throw new RuntimeException('Maximum three active requests.');
   $today=(int)$this->query("SELECT COUNT(*) FROM mucho_creator_events WHERE actor_kind='game' AND actor_id=? AND action='submitted' AND created_at>UTC_TIMESTAMP()-INTERVAL 1 DAY",[$a['id']])->fetchColumn();
   if($today>=5)throw new RuntimeException('Maximum five submissions per 24 hours.');
   if($r){$this->query("UPDATE mucho_creator_requests SET state='submitted',level_name=?,level_version=?,content_hash=?,author_note=?,cycle=cycle+1,revision=revision+1,reviewer_kind=NULL,reviewer_id=NULL,reviewer_name=NULL,submitted_at=UTC_TIMESTAMP() WHERE id=?",[$l['name'],$l['level_version'],$l['content_hash'],$note,$r['id']]);$id=(int)$r['id'];}
   else{$this->query('INSERT INTO mucho_creator_requests(level_id,account_id,level_name,level_version,content_hash,author_note) VALUES(?,?,?,?,?,?)',[$level,$a['id'],$l['name'],$l['level_version'],$l['content_hash'],$note]);$id=(int)$this->db->lastInsertId();}
   $r=$this->query('SELECT * FROM mucho_creator_requests WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
   $this->event($a,$r,'submitted',$note,['level_version'=>(int)$l['level_version'],'content_hash'=>$l['content_hash']]);return $id;
  });return $this->detail($a,$id);
 }
 private function selection(): string {return "SELECT r.*,a.username creator_name,l.stars,l.rate_tier,l.level_version current_level_version,(r.content_hash<>SHA2(l.level_data,256) OR r.level_version<>l.level_version) level_changed FROM mucho_creator_requests r JOIN accounts a ON a.account_id=r.account_id JOIN levels l ON l.level_id=r.level_id";}
 private function clean(array $r): array {
  foreach(['id','level_id','account_id','revision','cycle','level_version','current_level_version','stars','rate_tier'] as $key)if(isset($r[$key]))$r[$key]=(int)$r[$key];
  $r['level_changed']=(bool)$r['level_changed'];unset($r['content_hash']);return $r;
 }
 public function listing(array $a,string $view,int $page=1): array {
  $page=max(1,min(1000,$page));$offset=($page-1)*10;
  if($view==='levels'){
   if($a['kind']!=='game')throw new RuntimeException('Player account required.');
   $rows=$this->query("SELECT level_id,name,level_version,stars FROM levels WHERE account_id=? AND is_deleted=0 AND is_unlisted=0 AND unlisted2=0 ORDER BY level_id DESC LIMIT 11 OFFSET $offset",[$a['id']])->fetchAll(PDO::FETCH_ASSOC);
  }else{
   if($view==='archive'){$this->reviewer($a);$where="r.state NOT IN ('submitted','in_review')";$args=[];}
   elseif($view==='queue'){$this->reviewer($a);$where="r.state IN ('submitted','in_review')";$args=[];}
   elseif($view==='mine'&&$a['kind']==='game'){$where='r.account_id=?';$args=[$a['id']];}
   else throw new RuntimeException('Unknown Creator Hub view.');
   $rows=$this->query($this->selection()." WHERE $where ORDER BY r.updated_at DESC,r.id DESC LIMIT 11 OFFSET $offset",$args)->fetchAll(PDO::FETCH_ASSOC);$rows=array_map($this->clean(...),$rows);
  }
  $more=count($rows)>10;return ['items'=>array_slice($rows,0,10),'page'=>$page,'has_next'=>$more,'can_review'=>(bool)$a['review'],'view'=>$view];
 }
 public function detail(array $a,int $id): array {
  $r=$this->query($this->selection().' WHERE r.id=?',[$id])->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Request not found.');
  if(!$a['review'])$this->owned($a,$r);
  $r=$this->clean($r);$r['can_review']=(bool)$a['review'];$r['mine']=$a['kind']==='game'&&$a['id']===$r['account_id'];
  $r['assigned_to_me']=$r['reviewer_kind']===$a['kind']&&(int)$r['reviewer_id']===$a['id'];
  $r['history']=$this->query('SELECT id,cycle,actor_kind,actor_name,action,note,created_at FROM mucho_creator_events WHERE request_id=? ORDER BY id DESC LIMIT 30',[$id])->fetchAll(PDO::FETCH_ASSOC);
  return $r;
 }
 public function claim(array $a,int $id,int $revision): array {
  $this->reviewer($a);$this->transaction(function()use($a,$id,$revision):void {
   $r=$this->lock($id);if($a['kind']==='game'&&$a['id']===(int)$r['account_id'])throw new RuntimeException('Another moderator must review your own level.');if($r['state']==='in_review'&&$r['reviewer_kind']===$a['kind']&&(int)$r['reviewer_id']===$a['id'])return;
   $this->revision($r,$revision);if($r['state']!=='submitted')throw new RuntimeException('Request is already assigned or closed.');
   $this->query("UPDATE mucho_creator_requests SET state='in_review',reviewer_kind=?,reviewer_id=?,reviewer_name=?,revision=revision+1 WHERE id=?",[$a['kind'],$a['id'],$a['name'],$id]);$this->event($a,$r,'in_review','Review started.');
  });return $this->detail($a,$id);
 }
 public function decide(array $a,int $id,int $revision,string $state,string $note): array {
  $this->reviewer($a);if(!in_array($state,['changes_requested','approved','rejected'],true))throw new RuntimeException('Invalid decision.');$note=$this->note($note,$state!=='approved');
  $this->transaction(function()use($a,$id,$revision,$state,$note):void {
   $r=$this->lock($id);$this->revision($r,$revision);
   if($r['state']!=='in_review'||$r['reviewer_kind']!==$a['kind']||(int)$r['reviewer_id']!==$a['id'])throw new RuntimeException('Take this request before reviewing it.');
   $l=$this->activeLevel((int)$r['level_id']);
   if((int)$l['account_id']!==(int)$r['account_id']||$l['content_hash']!==$r['content_hash']||(int)$l['level_version']!==(int)$r['level_version'])throw new RuntimeException('Level changed. Ask the creator to resubmit the updated level.');
   $this->query('UPDATE mucho_creator_requests SET state=?,revision=revision+1 WHERE id=?',[$state,$id]);$this->event($a,$r,$state,$note);
  });return $this->detail($a,$id);
 }
 public function release(array $a,int $id,int $revision): array {
  $this->reviewer($a);$this->transaction(function()use($a,$id,$revision):void {
   $r=$this->lock($id);$this->revision($r,$revision);
   if($r['state']!=='in_review'||($a['kind']!=='admin'&&($r['reviewer_kind']!==$a['kind']||(int)$r['reviewer_id']!==$a['id'])))throw new RuntimeException('Only the assigned reviewer or an administrator can release this request.');
   $this->query("UPDATE mucho_creator_requests SET state='submitted',revision=revision+1,reviewer_kind=NULL,reviewer_id=NULL,reviewer_name=NULL WHERE id=?",[$id]);$this->event($a,$r,'released','Returned to the review queue.');
  });return $this->detail($a,$id);
 }
 public function withdraw(array $a,int $id,int $revision): array {
  $this->transaction(function()use($a,$id,$revision):void {
   $r=$this->lock($id);$this->owned($a,$r);$this->revision($r,$revision);
   if(!in_array($r['state'],['submitted','in_review','changes_requested'],true))throw new RuntimeException('Request is already closed.');
   $this->query("UPDATE mucho_creator_requests SET state='withdrawn',revision=revision+1 WHERE id=?",[$id]);$this->event($a,$r,'withdrawn','Withdrawn by creator.');
  });return $this->detail($a,$id);
 }
}
