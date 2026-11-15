<?php
/** Copy native-only storage into the original schema, in bounded transactions. */
declare(strict_types=1);
function mucho7_domain_table(PDO $db,$table)
{
    $query=$db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$query->execute(array($table));return (bool)$query->fetchColumn();
}
function mucho7_domain_import_stages()
{
    return array('profiles','level_details','level_comments','account_comments','votes','friends','legacy_friends','friend_requests','blocks','messages','cloud_saves');
}
function mucho7_domain_complete(PDO $db)
{
    // One query on the hot request path instead of information_schema + SELECT.
    // Treat only a missing table as incomplete; never hide other database errors.
    try {
        $state=$db->query('SELECT stage FROM php7_core_import_state WHERE id=1')->fetchColumn();
        return $state!==false && (int)$state>=count(mucho7_domain_import_stages());
    }catch(PDOException $error){
        $details=$error->errorInfo;
        if($error->getCode()==='42S02' || (is_array($details) && isset($details[1]) && (int)$details[1]===1146))return false;
        throw $error;
    }
}
function mucho7_domain_import_insert(PDO $db,$table,$values)
{
    // Every identifier comes from the fixed mappings below, never request data.
    $columns=array_keys($values);$quoted='`'.implode('`,`',$columns).'`';
    $query=$db->prepare('INSERT IGNORE INTO `'.$table.'` ('.$quoted.') VALUES('.implode(',',array_fill(0,count($values),'?')).')');
    $query->execute(array_values($values));
    if($query->rowCount()===0){
        $where=array();foreach($columns as $column)$where[]='`'.$column.'` <=> ?';
        $check=$db->prepare('SELECT 1 FROM `'.$table.'` WHERE '.implode(' AND ',$where).' LIMIT 1');$check->execute(array_values($values));
        if(!$check->fetchColumn())throw new RuntimeException('Native data conflicts with existing core data.');
    }
}
function mucho7_domain_import_profile(PDO $db,$row)
{
    $details=json_decode($row['payload'],true);if(!is_array($details))throw new RuntimeException('Native profile details are invalid.');
    $profiles=array();$accounts=array();
    foreach(array('ship','ball','ufo','wave','robot','spider','swing','jetpack','explosion','special','color3','demon_info','star_info','platformer_info') as $field){
        if(array_key_exists($field,$details))$profiles[$field]=$details[$field];
    }
    foreach(array('message_state'=>'messages_state','friend_request_state'=>'friend_requests_state','comment_history_state'=>'comments_state',
        'youtube'=>'youtube_url','twitter'=>'twitter','twitch'=>'twitch','discord'=>'discord','instagram'=>'instagram','tiktok'=>'tiktok','custom_link'=>'custom_link') as $from=>$to){
        if(array_key_exists($from,$details))$accounts[$to]=$details[$from];
    }
    foreach(array('profiles'=>$profiles,'accounts'=>$accounts) as $table=>$values){
        if(!$values)continue;$sets=array();foreach(array_keys($values) as $field)$sets[]='`'.$field.'`=?';
        $values[]=$row['account_id'];$query=$db->prepare('UPDATE '.$table.' SET '.implode(',',$sets).' WHERE account_id=?');$query->execute(array_values($values));
    }
}
function mucho7_domain_save_key(PDO $db,$root)
{
    // A fresh PHP7 -> full-core migration can have zero legacy cloud saves.
    // In that case the importer never calls this function. Provision the
    // persistent key at migration completion and, for already upgraded
    // installations, before the first backup. Never rotate an old key.
    $path=$root.'/config/cloudsave.key';
    $lockPath=$root.'/config/php7-cloudsave-key.lock';
    if(is_link($path)||is_link($lockPath))throw new RuntimeException('Cloud-save key or lock must not be a symlink.');
    $lock=@fopen($lockPath,'c');
    if(!is_resource($lock)||!flock($lock,LOCK_EX)){
        if(is_resource($lock))fclose($lock);
        throw new RuntimeException('Unable to lock the cloud-save key.');
    }
    try{
        @chmod($lockPath,0600);
        clearstatcache(true,$path);
        if(is_link($path))throw new RuntimeException('The cloud-save key must not be a symlink.');
        if(!is_file($path)){
            // Fail closed if any prior cloud record exists. A missing private
            // key must never be silently replaced: ciphertext would become
            // permanently unreadable after a new encrypted write.
            if($db->query('SELECT 1 FROM mucho_cloud_saves LIMIT 1')->fetchColumn()!==false)
                throw new RuntimeException('Existing encrypted saves require their original private key.');
            try{
                $native=$db->query('SELECT 1 FROM php7_cloud_saves WHERE LEFT(save_data,8)='MC7ENC1:' LIMIT 1')->fetchColumn();
                if($native!==false)throw new RuntimeException('Existing native cloud saves require their original private key.');
            }catch(PDOException $error){
                $info=$error->errorInfo;
                // A fresh schema may omit the pre-migration native table.
                // Ignore only "table does not exist", not unrelated DB failures.
                if($error->getCode()!=='42S02'&&
                    !(is_array($info)&&isset($info[1])&&(int)$info[1]===1146))throw $error;
            }
            mucho7_private_write($path,base64_encode(random_bytes(32))."\n");
        }
        $raw=@file_get_contents($path);
        if(!is_string($raw))throw new RuntimeException('The cloud-save key is unreadable.');
        $encoded=trim($raw);
        // Preserve legacy 64-character HEX keys as well as canonical Base64.
        $key=preg_match('/\\A[a-f0-9]{64}\\z/i',$encoded)===1?hex2bin($encoded):base64_decode($encoded,true);
        if(!is_string($key)||strlen($key)!==32)throw new RuntimeException('The cloud-save key is invalid.');
        return $key;
    }finally{
        flock($lock,LOCK_UN);
        fclose($lock);
    }
}
/** Caller must quiesce API/admin writes until activation; legacy tables remain. */
function mucho7_domain_import_step(PDO $db,$root,$batch=100)
{
    $db->exec('CREATE TABLE IF NOT EXISTS php7_core_import_state (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,stage INT UNSIGNED NOT NULL DEFAULT 0,
        row_offset BIGINT UNSIGNED NOT NULL DEFAULT 0,prepared TINYINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $db->exec('INSERT IGNORE INTO php7_core_import_state(id) VALUES(1)');
    $db->beginTransaction();
    try{
        $state=$db->query('SELECT * FROM php7_core_import_state WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        $stages=mucho7_domain_import_stages();$index=(int)$state['stage'];
        if($index>=count($stages)){$db->commit();return array('done'=>true,'stage'=>'complete','copied'=>0);}
        if(!(int)$state['prepared']){
            foreach(array('comments','account_comments','likes','friends','friend_requests','blocks','messages','mucho_cloud_saves') as $target){
                if((int)$db->query('SELECT COUNT(*) FROM '.$target)->fetchColumn()!==0)throw new RuntimeException('Existing core data must be reconciled before importing native storage.');
            }
            $db->exec('UPDATE profiles SET cube=icon_id');
            $db->exec('UPDATE php7_core_import_state SET prepared=1 WHERE id=1');
        }
        $stage=$stages[$index];$offset=(int)$state['row_offset'];$limit=$stage==='cloud_saves'?1:max(1,min(100,(int)$batch));
        $tables=array('profiles'=>'php7_user_details','level_details'=>'php7_level_details','level_comments'=>'php7_comments','account_comments'=>'php7_comments',
            'votes'=>'php7_votes','friends'=>'php7_friends','legacy_friends'=>'php7_friendships','friend_requests'=>'php7_friend_requests','blocks'=>'php7_blocks','messages'=>'php7_messages','cloud_saves'=>'php7_cloud_saves');
        $table=$tables[$stage];$rows=array();
        $legacyFriends=mucho7_domain_schema_member($db,'php7_friend_requests','sender_id');
        $activeSource=!($stage==='friends' && $legacyFriends) && !($stage==='legacy_friends' && !$legacyFriends);
        if($activeSource && mucho7_domain_table($db,$table)){
            $where=$stage==='level_comments'?' WHERE kind=1':($stage==='account_comments'?' WHERE kind IN (0,2)':'');
            $orders=array('profiles'=>'account_id','level_details'=>'level_id','level_comments'=>'id','account_comments'=>'id','votes'=>'type,item_id,account_id',
                'friends'=>'account_id,friend_account_id','legacy_friends'=>'account_low,account_high','friend_requests'=>'id','blocks'=>'account_id,blocked_account_id','messages'=>'id','cloud_saves'=>'account_id');
            $query=$db->query('SELECT * FROM '.$table.$where.' ORDER BY '.$orders[$stage].' LIMIT '.$limit.' OFFSET '.$offset);$rows=$query->fetchAll(PDO::FETCH_ASSOC);$query->closeCursor();
        }
        foreach($rows as $row){
            if($stage==='profiles')mucho7_domain_import_profile($db,$row);
            elseif($stage==='level_details')$db->prepare('UPDATE levels SET level_info=? WHERE level_id=?')->execute(array($row['level_info'],$row['level_id']));
            elseif($stage==='level_comments'||$stage==='account_comments'){
                $values=array('id'=>$row['id'],'account_id'=>$row['account_id'],'content'=>$row['content'],'likes'=>$row['likes'],'is_spam'=>$row['is_spam'],'created_at'=>$row['created_at']);
                if($stage==='level_comments'){$values['level_id']=$row['level_id'];$values['percent']=$row['percent'];}
                mucho7_domain_import_insert($db,$stage==='level_comments'?'comments':'account_comments',$values);
            }elseif($stage==='votes')mucho7_domain_import_insert($db,'likes',array('item_id'=>$row['item_id'],'type'=>$row['type'],'account_id'=>$row['account_id'],'ip'=>'','is_like'=>(int)$row['value']>0?1:0));
            elseif($stage==='friends')mucho7_domain_import_insert($db,'friends',array('account_id'=>$row['account_id'],'friend_account_id'=>$row['friend_account_id'],'is_new'=>$row['is_new']));
            elseif($stage==='legacy_friends'){
                mucho7_domain_import_insert($db,'friends',array('account_id'=>$row['account_low'],'friend_account_id'=>$row['account_high'],'is_new'=>$row['low_new']));
                mucho7_domain_import_insert($db,'friends',array('account_id'=>$row['account_high'],'friend_account_id'=>$row['account_low'],'is_new'=>$row['high_new']));
            }elseif($stage==='friend_requests'){
                $legacy=isset($row['sender_id']);mucho7_domain_import_insert($db,'friend_requests',array('id'=>$row['id'],
                    'account_id'=>$legacy?$row['sender_id']:$row['account_id'],'to_account_id'=>$legacy?$row['receiver_id']:$row['to_account_id'],
                    'comment'=>$row['comment'],'is_read'=>$legacy?((int)$row['is_new']===1?0:1):$row['is_read'],'created_at'=>$row['created_at']));
            }elseif($stage==='blocks')mucho7_domain_import_insert($db,'blocks',array('account_id'=>$row['account_id'],'blocked_account_id'=>$row['blocked_account_id']));
            elseif($stage==='messages'){
                $values=array();foreach(array('id','account_id','to_account_id','subject','body','is_read','is_sender_deleted','is_receiver_deleted','created_at') as $field)$values[$field]=$row[$field];
                mucho7_domain_import_insert($db,'messages',$values);
            }elseif($stage==='cloud_saves'){
                $stored=$row['save_data'];
                if(!is_string($stored))throw new RuntimeException('Invalid native cloud-save data.');
                $key=mucho7_domain_save_key($db,$root);
                if(!function_exists('mucho7_cloudsave_unseal')){
                    // In the source tree this module is adjacent to domain-data.
                    // A reviewed compiled build keeps domain-data under
                    // builds/<sha>/; the native adapter is two levels above.
                    $reader=__DIR__.'/cloudsave.php';
                    if(!is_file($reader))$reader=dirname(__DIR__,2).'/cloudsave.php';
                    if(!is_file($reader))throw new RuntimeException('Native cloud-save adapter is missing.');
                    require_once $reader;
                }
                $plain=mucho7_cloudsave_unseal($stored,$key);
                if(!hash_equals((string)$row['sha256'],hash('sha256',$plain)))throw new RuntimeException('Native cloud-save checksum does not match.');
                $nonce=random_bytes(12);$tag='';
                $encrypted=mucho7_openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,'',16);
                mucho7_domain_import_insert($db,'mucho_cloud_saves',array('account_id'=>$row['account_id'],'ciphertext'=>$encrypted,'nonce'=>$nonce,'auth_tag'=>$tag,
                    'sha256'=>$row['sha256'],'size_bytes'=>strlen($plain),'revision'=>1,'backed_up_at'=>$row['updated_at']));
            }
        }
        $finished=count($rows)<$limit;
        $query=$db->prepare('UPDATE php7_core_import_state SET stage=?,row_offset=? WHERE id=1');
        $query->execute(array($finished?$index+1:$index,$finished?0:$offset+count($rows)));
        $db->commit();return array('done'=>$finished&&$index+1===count($stages),'stage'=>$stage,'copied'=>count($rows));
    }catch(\Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
