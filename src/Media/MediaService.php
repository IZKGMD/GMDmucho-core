<?php
declare(strict_types=1);
namespace MuchoCore\Media;
use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Core\SystemRepository;
use MuchoCore\Social\RelationshipRepository;
use PDO;
use RuntimeException;
use Throwable;

final readonly class MediaService {
    public function __construct(private PDO $db,private AccountAuthenticator $auth,
        private ImageModerator $moderator=new OpenAiImageModerator()){}
    private function q(string $sql,array $args=[]): \PDOStatement {
        $q=$this->db->prepare($sql);$q->execute($args);return $q;
    }
    public function configuration(): array {
        $settings=new SystemRepository($this->db);
        $mode=$settings->setting('media.moderation.mode','manual');
        if(!in_array($mode,['off','manual','openai'],true))$mode='manual';
        return ['images_enabled'=>$settings->boolSetting('media.images.enabled'),
            'moderation'=>$mode,'max_upload_bytes'=>MessageImage::MAX_INPUT,'max_image_bytes'=>MessageImage::MAX_OUTPUT];
    }
    private function enabled(): void {
        if(!$this->configuration()['images_enabled'])throw new RuntimeException('Images are disabled on this server.');
    }
    private function blocked(int $a,int $b): bool {
        return (bool)$this->q('SELECT 1 FROM blocks WHERE (account_id=? AND blocked_account_id=?) OR (account_id=? AND blocked_account_id=?) LIMIT 1',[$a,$b,$b,$a])->fetchColumn();
    }
    private function gate(int $account,string $scope,int $target): void {
        if($target<1||$target>2147483647||!$this->q('SELECT 1 FROM accounts WHERE account_id=? AND is_active=1 AND is_banned=0',[$account])->fetchColumn())
            throw new RuntimeException('Conversation is unavailable.');
        if($scope==='message'){
            if(!(new RelationshipRepository($this->db))->canMessage($account,$target))throw new RuntimeException('Recipient does not accept messages from you.');
        }elseif($scope==='room'){
            if(!$this->q('SELECT 1 FROM mucho_social_rooms r JOIN mucho_social_members m ON m.room_id=r.id WHERE r.id=? AND m.account_id=? AND r.expires_at>UTC_TIMESTAMP()',[$target,$account])->fetchColumn())
                throw new RuntimeException('Room is unavailable.');
        }else throw new RuntimeException('Choose a message or room.');
    }
    private static function text(string $value,int $max): string {
        if(!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$max||strlen($value)>1000)
            throw new RuntimeException('Text is too long or invalid.');
        return trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f<>]/u',' ',$value)??'');
    }
    private function denyHash(string $hash): void {
        if($this->q('SELECT 1 FROM mucho_media_blocklist WHERE sha256=?',[$hash])->fetchColumn())
            throw new RuntimeException('This image is blocked by server policy.');
    }
    public function upload(int $id,string $credential,string $scope,int $target,string $encoded,string $caption,string $subject): array {
        $this->auth->authenticate($id,$credential);$this->enabled();$this->gate($id,$scope,$target);
        $caption=self::text($caption,500);$subject=self::text($subject,80);
        if(strlen($subject)>160)throw new RuntimeException('Shorten the image subject.');
        $config=$this->configuration();
        if((int)$this->q('SELECT COUNT(*) FROM mucho_message_media WHERE account_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR',[$id])->fetchColumn()>=20)
            throw new RuntimeException('Image rate limit reached. Try again later.');
        if((int)$this->q('SELECT COUNT(*) FROM mucho_message_media WHERE account_id=? AND image_data IS NOT NULL',[$id])->fetchColumn()>=50)
            throw new RuntimeException('Image storage limit reached. Remove an older image first.');
        $source=MessageImage::decode($encoded);$sourceHash=hash('sha256',$source);
        // Known prohibited/suspected material never goes to an external classifier.
        $this->denyHash($sourceHash);
        $image=MessageImage::render($source);unset($source);
        $this->denyHash($image['hash']);
        $this->db->beginTransaction();
        try {
            $this->q('SELECT account_id FROM accounts WHERE account_id=? FOR UPDATE',[$id]);
            if((int)$this->q('SELECT COUNT(*) FROM mucho_message_media WHERE account_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR',[$id])->fetchColumn()>=20)
                throw new RuntimeException('Image rate limit reached. Try again later.');
            if((int)$this->q('SELECT COUNT(*) FROM mucho_message_media WHERE account_id=? AND image_data IS NOT NULL',[$id])->fetchColumn()>=50)
                throw new RuntimeException('Image storage limit reached. Remove an older image first.');
            $this->gate($id,$scope,$target);
            $this->q('INSERT INTO mucho_message_media(account_id,scope,target_id,subject,caption,source_hash,image_hash,width,height,image_data) VALUES(?,?,?,?,?,?,?,?,?,?)',
                [$id,$scope,$target,$subject,$caption,$sourceHash,$image['hash'],$image['width'],$image['height'],$image['bytes']]);
            $media=(int)$this->db->lastInsertId();$this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        $decision=match($config['moderation']){
            'off'=>['state'=>'approved','reason'=>'moderation_disabled'],
            'openai'=>$this->moderator->classify($image['bytes'],$subject."\n".$caption),
            default=>['state'=>'pending','reason'=>'manual_review']};
        if(!in_array($decision['state']??null,['approved','rejected','pending'],true))$decision=['state'=>'pending','reason'=>'moderator_invalid_response'];
        if($decision['state']==='approved'&&$this->configuration()!==$config)$decision=['state'=>'pending','reason'=>'policy_changed'];
        $this->review($media,$decision['state'],$decision['reason']??'moderation',false);
        return $this->metadata($media,$id);
    }
    private function metadata(int $media,int $owner): array {
        $row=$this->q('SELECT id,state,width,height,message_id,scope,target_id FROM mucho_message_media WHERE id=? AND account_id=?',[$media,$owner])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Image is unavailable.');return $row;
    }
    public function review(int $media,string $state,string $reason,bool $block=false): void {
        if(!in_array($state,['approved','rejected','pending'],true))throw new RuntimeException('Invalid review state.');
        $this->db->beginTransaction();
        try {
            $row=$this->q('SELECT * FROM mucho_message_media WHERE id=? FOR UPDATE',[$media])->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new RuntimeException('Image is unavailable.');
            if($row['state']==='rejected'&&$state!=='rejected')throw new RuntimeException('Rejected image cannot be restored.');
            if($row['state']==='approved'&&$state!=='rejected'){$this->db->commit();return;}
            if($state==='approved'){
                $this->enabled();
                try {$this->gate((int)$row['account_id'],$row['scope'],(int)$row['target_id']);}
                catch(RuntimeException){$state='rejected';$reason='conversation_access_revoked';}
            }
            if($state==='approved'){
                if($reason==='admin_approved'&&$row['reason']==='user_report'){
                    $others=(int)$this->q("SELECT COUNT(*) FROM mucho_message_media WHERE id<>? AND state='pending' AND reason='user_report' AND (source_hash=? OR image_hash=?)",[$media,$row['source_hash'],$row['image_hash']])->fetchColumn();
                    if($others===0)foreach(array_unique([$row['source_hash'],$row['image_hash']]) as $hash)
                        $this->q("DELETE FROM mucho_media_blocklist WHERE sha256=? AND reason='reported_material'",[$hash]);
                }
                $this->denyHash($row['source_hash']);$this->denyHash($row['image_hash']);
                if(!is_string($row['image_data'])||$row['image_data']==='')throw new RuntimeException('Image data is unavailable.');
                if($row['scope']==='message'&&$row['message_id']===null){
                    $encode=static fn(string $s):string=>strtr(base64_encode($s),'+/','-_');
                    $this->q('INSERT INTO messages(account_id,to_account_id,subject,body) VALUES(?,?,?,?)',
                        [$row['account_id'],$row['target_id'],$encode($row['subject']?:'Image'),$encode('[Image]'.($row['caption']!==''?"\n".$row['caption']:''))]);
                    $message=(int)$this->db->lastInsertId();
                    $this->q('UPDATE mucho_message_media SET message_id=? WHERE id=?',[$message,$media]);
                }elseif($row['scope']==='room'&&!$this->q('SELECT 1 FROM mucho_room_chat WHERE media_id=?',[$media])->fetchColumn()){
                    $this->q('INSERT INTO mucho_room_chat(room_id,account_id,content,media_id) VALUES(?,?,?,?)',[$row['target_id'],$row['account_id'],$row['caption'],$media]);
                }
            }
            if($state==='rejected'){
                if($block)foreach(array_unique([$row['source_hash'],$row['image_hash']]) as $hash)
                    $this->q('INSERT IGNORE INTO mucho_media_blocklist(sha256,reason) VALUES(?,?)',[$hash,'server_policy']);
                $this->q('UPDATE mucho_message_media SET image_data=NULL WHERE id=?',[$media]);
            }
            $this->q('UPDATE mucho_message_media SET state=?,reason=?,reviewed_at=UTC_TIMESTAMP() WHERE id=?',[$state,substr($reason,0,128),$media]);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function messageAccess(int $viewer,int $message): bool {
        $row=$this->q('SELECT account_id,to_account_id,is_sender_deleted,is_receiver_deleted FROM messages WHERE id=?',[$message])->fetch(PDO::FETCH_ASSOC);
        if(!$row||$this->blocked((int)$row['account_id'],(int)$row['to_account_id']))return false;
        return ((int)$row['account_id']===$viewer&&(int)$row['is_sender_deleted']===0)||
            ((int)$row['to_account_id']===$viewer&&(int)$row['is_receiver_deleted']===0);
    }
    public function attachments(int $id,string $credential,int $message): array {
        $this->auth->authenticate($id,$credential);$this->enabled();
        if(!$this->messageAccess($id,$message))throw new RuntimeException('Message is unavailable.');
        return ['images'=>$this->q("SELECT id,width,height FROM mucho_message_media WHERE message_id=? AND state='approved' AND image_data IS NOT NULL LIMIT 10",[$message])->fetchAll(PDO::FETCH_ASSOC)];
    }
    public function image(int $id,string $credential,int $media): string {
        $this->auth->authenticate($id,$credential);$this->enabled();
        $row=$this->q("SELECT * FROM mucho_message_media WHERE id=? AND state='approved'",[$media])->fetch(PDO::FETCH_ASSOC);
        if(!$row||!is_string($row['image_data']))throw new RuntimeException('Image is unavailable.');
        if($row['scope']==='message'){
            if(!$this->messageAccess($id,(int)$row['message_id']))throw new RuntimeException('Image is unavailable.');
        }else{$this->gate($id,'room',(int)$row['target_id']);if($this->blocked($id,(int)$row['account_id']))throw new RuntimeException('Image is unavailable.');}
        if(!$this->q('SELECT 1 FROM accounts WHERE account_id=? AND is_active=1 AND is_banned=0',[$row['account_id']])->fetchColumn())throw new RuntimeException('Image is unavailable.');
        return $row['image_data'];
    }
    public function remove(int $id,string $credential,int $media): array {
        $this->auth->authenticate($id,$credential);$this->metadata($media,$id);
        $this->review($media,'rejected','owner_removed');return ['removed'=>true];
    }
    public function mine(int $id,string $credential): array {
        $this->auth->authenticate($id,$credential);
        return ['images'=>$this->q('SELECT id,state,width,height,created_at FROM mucho_message_media WHERE account_id=? AND image_data IS NOT NULL ORDER BY id DESC LIMIT 50',[$id])->fetchAll(PDO::FETCH_ASSOC)];
    }
    public function chat(int $id,string $credential,int $room,int $before=0): array {
        $this->auth->authenticate($id,$credential);$this->gate($id,'room',$room);
        $filter=$before>0?' AND c.id<?':'';$args=[$room,$id,$id];if($before>0)$args[]=$before;
        $rows=$this->q("SELECT c.id,c.account_id,a.username,c.content,c.created_at,
            CASE WHEN m.state='approved' AND m.image_data IS NOT NULL THEN m.id ELSE NULL END media_id
            FROM mucho_room_chat c JOIN accounts a ON a.account_id=c.account_id
            LEFT JOIN mucho_message_media m ON m.id=c.media_id
            WHERE c.room_id=? AND a.is_active=1 AND a.is_banned=0
            AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.account_id=? AND b.blocked_account_id=c.account_id) OR (b.account_id=c.account_id AND b.blocked_account_id=?))
            $filter ORDER BY c.id DESC LIMIT 30",$args)->fetchAll(PDO::FETCH_ASSOC);
        return ['messages'=>array_reverse($rows),'images_enabled'=>$this->configuration()['images_enabled']];
    }
    public function sendChat(int $id,string $credential,int $room,string $text): array {
        $this->auth->authenticate($id,$credential);$this->gate($id,'room',$room);$text=self::text($text,500);
        if($text==='')throw new RuntimeException('Write a message.');
        if($this->configuration()['moderation']==='openai'){
            $result=$this->moderator->classify('',$text);
            if(($result['state']??'pending')!=='approved')throw new RuntimeException('Message did not pass moderation. Try later or contact a moderator.');
        }
        $this->db->beginTransaction();try{
            $this->q('SELECT account_id FROM accounts WHERE account_id=? FOR UPDATE',[$id]);$this->gate($id,'room',$room);
            if((int)$this->q('SELECT COUNT(*) FROM mucho_room_chat WHERE account_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 MINUTE',[$id])->fetchColumn()>=15)
                throw new RuntimeException('Chat rate limit reached.');
            $this->q('INSERT INTO mucho_room_chat(room_id,account_id,content) VALUES(?,?,?)',[$room,$id,$text]);$this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return $this->chat($id,$credential,$room);
    }
    public function report(int $id,string $credential,int $media,string $reason='other'): array {
        // Prove actual access before accepting a report. Quarantine immediately;
        // reviewers may re-approve false reports without republishing the message.
        $this->image($id,$credential,$media);
        if(!in_array($reason,['nsfw','violence','child_safety','other'],true))throw new RuntimeException('Choose a report reason.');
        $this->db->beginTransaction();try{
            $this->q('SELECT account_id FROM accounts WHERE account_id=? FOR UPDATE',[$id]);
            if((int)$this->q('SELECT COUNT(*) FROM mucho_media_reports WHERE account_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR',[$id])->fetchColumn()>=20)
                throw new RuntimeException('Report rate limit reached.');
            $row=$this->q("SELECT source_hash,image_hash FROM mucho_message_media WHERE id=? AND state='approved' FOR UPDATE",[$media])->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new RuntimeException('Image is already under review.');
            $this->q('INSERT INTO mucho_media_reports(media_id,account_id,reason) VALUES(?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason)',[$media,$id,$reason]);
            foreach(array_unique([$row['source_hash'],$row['image_hash']]) as $hash)
                $this->q("INSERT IGNORE INTO mucho_media_blocklist(sha256,reason) VALUES(?,'reported_material')",[$hash]);
            $this->q("UPDATE mucho_message_media SET state='pending',reason='user_report',reviewed_at=NULL WHERE id=?",[$media]);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return ['reported'=>true];
    }
}
