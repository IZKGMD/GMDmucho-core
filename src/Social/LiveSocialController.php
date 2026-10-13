<?php
declare(strict_types=1);
namespace MuchoCore\Social;
use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use PDO;
use RuntimeException;
use Throwable;
final readonly class LiveSocialController {
 public function __construct(private PDO $db,private AccountAuthenticator $auth){}
 private function q(string $sql,array $args=[]): \PDOStatement {$q=$this->db->prepare($sql);$q->execute($args);return $q;}
 private function visible(int $a,int $b): bool {
  if($a===$b)return true;
  return (bool)$this->q('SELECT 1 FROM friends WHERE account_id=? AND friend_account_id=?',[$a,$b])->fetchColumn()
   && !$this->q('SELECT 1 FROM blocks WHERE (account_id=? AND blocked_account_id=?) OR (account_id=? AND blocked_account_id=?)',[$a,$b,$b,$a])->fetchColumn();
 }
 public function handle(Request $r): Response {
  try {
   $id=$r->postInt('accountID');$this->auth->authenticate($id,$r->gdCredential());
   $op=$r->postString('action');$target=$r->postInt('targetID');$room=$r->postInt('roomID');
   $data=match($op){
    'heartbeat'=>$this->heartbeat($id,$r->postString('activity'),$r->postInt('levelID')),
    'friends'=>$this->friends($id), 'profile'=>$this->profile($id,$target?:$id),
    'react'=>$this->react($id,$target,$r->postString('achievement'),$r->postString('reaction')),
    'room-create'=>$this->createRoom($id,$r->postInt('levelID')),
    'room'=>$this->room($id,$room), 'invite'=>$this->invite($id,$room,$target),
    'invites'=>$this->invites($id), 'accept'=>$this->accept($id,$room),
    'decline'=>$this->decline($id,$room), 'leave'=>$this->leave($id,$room),
    default=>throw new RuntimeException('Unknown social action.')};
   return Response::json(['ok'=>true,'data'=>$data]);
  } catch(RuntimeException $e){return Response::json(['ok'=>false,'error'=>$e->getMessage()],$e->getMessage()==='Unauthorized.'?401:400);}
  catch(Throwable){if($this->db->inTransaction())$this->db->rollBack();return Response::json(['ok'=>false,'error'=>'Social service is unavailable.'],503);}
 }
 private function heartbeat(int $id,string $activity,int $level): array {
  if(!in_array($activity,['menu','playing','editor'],true)||$level<0||$level>2147483647)throw new RuntimeException('Invalid activity.');
  $this->q('INSERT INTO mucho_presence(account_id,activity,level_id,seen_at) VALUES(?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE activity=VALUES(activity),level_id=VALUES(level_id),seen_at=UTC_TIMESTAMP()',[$id,$activity,$activity==='playing'?$level:0]);
  return ['activity'=>$activity,'ttl'=>90];
 }
 private function friends(int $id): array {
  return ['friends'=>$this->q("SELECT a.account_id,a.username,CASE WHEN p.seen_at>UTC_TIMESTAMP()-INTERVAL 90 SECOND THEN p.activity ELSE 'offline' END activity,CASE WHEN p.seen_at>UTC_TIMESTAMP()-INTERVAL 90 SECOND THEN p.level_id ELSE 0 END level_id FROM friends f JOIN accounts a ON a.account_id=f.friend_account_id LEFT JOIN mucho_presence p ON p.account_id=a.account_id WHERE f.account_id=? AND a.is_active=1 AND a.is_banned=0 AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.account_id=? AND b.blocked_account_id=a.account_id) OR (b.account_id=a.account_id AND b.blocked_account_id=?)) ORDER BY a.username LIMIT 100",[$id,$id,$id])->fetchAll(PDO::FETCH_ASSOC)];
 }
 private function achievements(int $id): array {
  $profile=$this->q('SELECT stars,demons,diamonds FROM profiles WHERE account_id=?',[$id])->fetch(PDO::FETCH_ASSOC)?:[];$items=[];
  foreach(['stars'=>[100,1000,10000],'demons'=>[1,10,100],'diamonds'=>[1000,10000]] as $metric=>$steps)
   foreach($steps as $goal)if((int)($profile[$metric]??0)>=$goal)$items[]=['id'=>$metric.'_'.$goal,'metric'=>$metric,'goal'=>$goal];
  return $items;
 }
 private function profile(int $viewer,int $id): array {
  $a=$this->q('SELECT account_id,username FROM accounts WHERE account_id=? AND is_active=1 AND is_banned=0',[$id])->fetch(PDO::FETCH_ASSOC);
  if(!$a)throw new RuntimeException('Profile not found.');
  $p=$this->visible($viewer,$id)?$this->q("SELECT activity,level_id FROM mucho_presence WHERE account_id=? AND seen_at>UTC_TIMESTAMP()-INTERVAL 90 SECOND",[$id])->fetch(PDO::FETCH_ASSOC):false;
  $a['activity']=$p['activity']??'offline';$a['level_id']=$p['level_id']??0;$a['achievements']=$this->achievements($id);
  foreach($a['achievements'] as &$event){$event['reactions']=$this->q('SELECT reaction,COUNT(*) total FROM mucho_achievement_reactions WHERE target_id=? AND achievement=? GROUP BY reaction',[$id,$event['id']])->fetchAll(PDO::FETCH_ASSOC);$event['my_reaction']=$this->q('SELECT reaction FROM mucho_achievement_reactions WHERE target_id=? AND achievement=? AND reactor_id=?',[$id,$event['id'],$viewer])->fetchColumn()?:'';}
  return $a;
 }
 private function react(int $id,int $target,string $achievement,string $reaction): array {
  if(!$this->visible($id,$target))throw new RuntimeException('Reactions are available between friends.');
  if(!in_array($reaction,['like','fire','heart','clap',''],true)||!in_array($achievement,array_column($this->achievements($target),'id'),true))throw new RuntimeException('Achievement or reaction is unavailable.');
  if($reaction==='')$this->q('DELETE FROM mucho_achievement_reactions WHERE target_id=? AND achievement=? AND reactor_id=?',[$target,$achievement,$id]);
  else $this->q('INSERT INTO mucho_achievement_reactions(target_id,achievement,reactor_id,reaction) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE reaction=VALUES(reaction)',[$target,$achievement,$id,$reaction]);
  return $this->profile($id,$target);
 }
 private function createRoom(int $id,int $level): array {
  if($level<=0||!$this->q('SELECT 1 FROM levels WHERE level_id=?',[$level])->fetchColumn())throw new RuntimeException('Choose a published level.');
  $this->db->beginTransaction();try{
   $this->q('SELECT account_id FROM accounts WHERE account_id=? FOR UPDATE',[$id]);
   if($this->q('SELECT COUNT(*) FROM mucho_social_rooms WHERE owner_id=? AND expires_at>UTC_TIMESTAMP()',[$id])->fetchColumn()>=3)throw new RuntimeException('Close an existing room first.');$this->q('INSERT INTO mucho_social_rooms(owner_id,level_id,expires_at) VALUES(?,?,UTC_TIMESTAMP()+INTERVAL 2 HOUR)',[$id,$level]);$room=(int)$this->db->lastInsertId();$this->q('INSERT INTO mucho_social_members(room_id,account_id) VALUES(?,?)',[$room,$id]);$this->db->commit();}catch(Throwable $e){$this->db->rollBack();throw $e;}
  return $this->room($id,$room);
 }
 private function room(int $id,int $room): array {
  $r=$this->q('SELECT r.* FROM mucho_social_rooms r JOIN mucho_social_members m ON m.room_id=r.id WHERE r.id=? AND m.account_id=? AND r.expires_at>UTC_TIMESTAMP()',[$room,$id])->fetch(PDO::FETCH_ASSOC);
  if(!$r)throw new RuntimeException('Room is unavailable.');
  $r['members']=$this->q('SELECT a.account_id,a.username FROM mucho_social_members m JOIN accounts a ON a.account_id=m.account_id WHERE m.room_id=? ORDER BY m.joined_at LIMIT 20',[$room])->fetchAll(PDO::FETCH_ASSOC);return $r;
 }
 private function invite(int $id,int $room,int $to): array {
  if($id===$to||!$this->visible($id,$to))throw new RuntimeException('Invite a friend.');
  if(!$this->q('SELECT 1 FROM mucho_social_rooms WHERE id=? AND owner_id=? AND expires_at>UTC_TIMESTAMP()',[$room,$id])->fetchColumn())throw new RuntimeException('Only the room host can invite.');
  $this->q("INSERT INTO mucho_social_invites(room_id,from_id,to_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE state='pending'",[$room,$id,$to]);return ['invited'=>true];
 }
 private function invites(int $id): array {
  return ['invites'=>$this->q("SELECT i.room_id,i.from_id,a.username,r.level_id FROM mucho_social_invites i JOIN mucho_social_rooms r ON r.id=i.room_id JOIN accounts a ON a.account_id=i.from_id WHERE i.to_id=? AND i.state='pending' AND r.expires_at>UTC_TIMESTAMP() AND EXISTS(SELECT 1 FROM friends f WHERE f.account_id=i.to_id AND f.friend_account_id=i.from_id) AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.account_id=i.to_id AND b.blocked_account_id=i.from_id) OR (b.account_id=i.from_id AND b.blocked_account_id=i.to_id)) ORDER BY i.created_at DESC LIMIT 50",[$id])->fetchAll(PDO::FETCH_ASSOC)];
 }
 private function accept(int $id,int $room): array {
  $this->db->beginTransaction();try{
   $r=$this->q('SELECT * FROM mucho_social_rooms WHERE id=? AND expires_at>UTC_TIMESTAMP() FOR UPDATE',[$room])->fetch(PDO::FETCH_ASSOC);
   $invite=$this->q("SELECT * FROM mucho_social_invites WHERE room_id=? AND to_id=? AND state='pending'",[$room,$id])->fetch(PDO::FETCH_ASSOC);
   if(!$r||!$invite||!$this->visible($id,(int)$invite['from_id']))throw new RuntimeException('Invitation is unavailable.');
   if((int)$this->q('SELECT COUNT(*) FROM mucho_social_members WHERE room_id=?',[$room])->fetchColumn()>=20)throw new RuntimeException('Room is full.');
   $this->q('INSERT IGNORE INTO mucho_social_members(room_id,account_id) VALUES(?,?)',[$room,$id]);$this->q("UPDATE mucho_social_invites SET state='accepted' WHERE room_id=? AND to_id=?",[$room,$id]);$this->db->commit();
  }catch(Throwable $e){$this->db->rollBack();throw $e;}return $this->room($id,$room);
 }
 private function decline(int $id,int $room): array {$this->q("UPDATE mucho_social_invites SET state='declined' WHERE room_id=? AND to_id=? AND state='pending'",[$room,$id]);return ['declined'=>true];}
 private function leave(int $id,int $room): array {
  $this->q('DELETE FROM mucho_social_members WHERE room_id=? AND account_id=?',[$room,$id]);$this->q('DELETE FROM mucho_social_rooms WHERE id=? AND owner_id=?',[$room,$id]);return ['left'=>true];
 }
}
