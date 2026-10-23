<?php
/** Native friend requests, friends, blocks and private messages. PHP 7.0. */
declare(strict_types=1);
require_once __DIR__.'/social-legacy.php';

function mucho7_upgrade_social(PDO $pdo) {
    // Current schema is owned by the additive feature upgrade. Existing older
    // sender_id/receiver_id tables are retained and served by the legacy adapter.
    mucho7_upgrade_features($pdo);
}

function mucho7_social_blocked(PDO $pdo, $a, $b)
{
    $query = $pdo->prepare('SELECT 1 FROM php7_blocks WHERE
        (account_id=? AND blocked_account_id=?) OR (account_id=? AND blocked_account_id=?) LIMIT 1');
    $query->execute(array($a,$b,$b,$a));
    return $query->fetchColumn()!==false;
}

function mucho7_social_friend(PDO $pdo, $a, $b)
{
    $query = $pdo->prepare('SELECT 1 FROM php7_friends WHERE account_id=? AND friend_account_id=?');
    $query->execute(array($a,$b));
    return $query->fetchColumn()!==false;
}

function mucho7_social_lock(PDO $pdo, $a, $b)
{
    // Every pair mutation uses the same ascending account lock order.
    $query = $pdo->prepare('SELECT account_id FROM accounts WHERE account_id IN (?,?)
        AND is_active=1 AND is_banned=0 ORDER BY account_id FOR UPDATE');
    $query->execute(array(min($a,$b),max($a,$b)));
    return count($query->fetchAll(PDO::FETCH_COLUMN))===2;
}

function mucho7_social_wire($value, $max, $empty = false)
{
    return is_string($value) && strlen($value)<=$max
        && ($empty || $value!=='')
        && preg_match('/^[A-Za-z0-9+\/_-]*={0,2}$/D',$value)===1;
}

function mucho7_relationship_wire($row, $request, $includeStats = false)
{
    $peer = isset($row['peer_id']) ? (int)$row['peer_id'] : (int)$row['account_id'];
    $fields = array(1,mucho7_username($row['username']),2,(int)$row['user_id'],
        9,(int)$row['icon_id'],10,(int)$row['color1'],11,(int)$row['color2'],
        14,(int)$row['icon_type'],15,isset($row['special'])?(int)$row['special']:0,16,$peer);
    if ($request) {
        $fields = array_merge($fields,array(32,(int)$row['id'],35,$row['comment'],
            41,(int)$row['is_read']===0?1:0,37,gmdate('d/m/Y G.i',mucho7_epoch($row['created_at']))));
    } else {
        $fields = array_merge($fields,array(18,0,41,isset($row['is_new'])?(int)$row['is_new']:0));
        if ($includeStats) {
            foreach (array(3=>'stars',4=>'demons',8=>'creator_points',13=>'secret_coins',
                17=>'user_coins',46=>'diamonds',52=>'moons') as $key=>$column) {
                $fields[]=$key; $fields[]=(int)$row[$column];
            }
        }
    }
    return implode(':',$fields);
}

function mucho7_message_wire($row, $sent, $withBody)
{
    $fields = array(6,mucho7_username($row['username']),3,(int)$row['user_id'],
        2,(int)$row['peer_id'],1,(int)$row['id'],4,$row['subject'],
        8,(int)$row['is_read'],9,$sent?1:0);
    if ($withBody) { $fields[]=5; $fields[]=$row['body']; }
    $fields[]=7; $fields[]=gmdate('d/m/Y G.i',mucho7_epoch($row['created_at']));
    return implode(':',$fields);
}

function mucho7_social_read(PDO $pdo, $aid, $path, $data, $canWrite)
{
    $page = mucho7_number(isset($data['page'])?$data['page']:0,0,1000);
    $offset=$page*10; $sent=isset($data['getSent']) && (string)$data['getSent']==='1';
    if ($path==='/getgjfriendrequests') {
        $mode=mucho7_number(isset($data['getSent'])?$data['getSent']:0,0,2);
        $peer = $sent?'s.to_account_id':'s.account_id';
        $owner = $sent?'s.account_id':'s.to_account_id';
        $from = ' FROM php7_friend_requests s JOIN accounts a ON a.account_id='.$peer
            .' JOIN profiles p ON p.account_id=a.account_id WHERE '.$owner.'=? AND a.is_active=1 AND a.is_banned=0'
            .($sent?'':($mode===2?' AND s.is_read=2':' AND s.is_read<>2'));
        $count=$pdo->prepare('SELECT COUNT(*)'.$from); $count->execute(array($aid));
        $query=$pdo->prepare('SELECT '.mucho7_user_select().',s.id,s.comment,s.is_read,s.created_at,'.$peer.' AS peer_id'
            .$from.' ORDER BY s.id DESC LIMIT 10 OFFSET '.$offset); $query->execute(array($aid));
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        $rows=mucho7_enrich_users($pdo,$rows); $entries=array();
        foreach($rows as $row) { $entries[]=mucho7_relationship_wire($row,true); }
        return $entries?implode('|',$entries).'#'.(int)$count->fetchColumn().':'.$offset.':10':'-2';
    }
    if ($path==='/getgjuserlist') {
        $type=mucho7_number(isset($data['type'])?$data['type']:0,0,9);
        if (!in_array($type,array(0,1),true)) { return '-1'; }
        $table=$type===0?'php7_friends':'php7_blocks';
        $peer=$type===0?'friend_account_id':'blocked_account_id';
        $query=$pdo->prepare('SELECT '.mucho7_user_select().','.
            ($type===0?'s.is_new':'0 AS is_new').' FROM '.$table.' s
            JOIN accounts a ON a.account_id=s.'.$peer.' JOIN profiles p ON p.account_id=a.account_id
            WHERE s.account_id=? AND a.is_active=1 AND a.is_banned=0 ORDER BY a.username LIMIT 1000');
        $query->execute(array($aid)); $rows=mucho7_enrich_users($pdo,$query->fetchAll(PDO::FETCH_ASSOC));
        $includeStats=mucho7_number(isset($data['binaryVersion'])?$data['binaryVersion']:0,0,1000)>=51;
        $entries=array(); foreach($rows as $row){ $entries[]=mucho7_relationship_wire($row,false,$includeStats); }
        if ($type===0 && $canWrite) {
            $pdo->prepare('UPDATE php7_friends SET is_new=0 WHERE account_id=?')->execute(array($aid));
        }
        return $entries?implode('|',$entries):'-2';
    }
    if ($path==='/getgjmessages' || $path==='/downloadgjmessage') {
        $messageId=mucho7_number(isset($data['messageID'])?$data['messageID']:0,0,2147483647);
        if ($path==='/downloadgjmessage') {
            $query=$pdo->prepare('SELECT account_id,to_account_id FROM php7_messages WHERE id=?');
            $query->execute(array($messageId)); $message=$query->fetch(PDO::FETCH_ASSOC);
            if (!$message || ($aid!==(int)$message['account_id'] && $aid!==(int)$message['to_account_id'])) { return '-1'; }
            $sent=$aid===(int)$message['account_id'];
        }
        $owner=$sent?'s.account_id':'s.to_account_id';
        $deleted=$sent?'s.is_sender_deleted':'s.is_receiver_deleted';
        $peer=$sent?'s.to_account_id':'s.account_id';
        $from=' FROM php7_messages s JOIN accounts a ON a.account_id='.$peer.'
            JOIN profiles p ON p.account_id=a.account_id WHERE '.$owner.'=? AND '.$deleted.'=0';
        $params=array($aid);
        if ($path==='/downloadgjmessage') { $from.=' AND s.id=?'; $params[]=$messageId; }
        $count=$pdo->prepare('SELECT COUNT(*)'.$from); $count->execute($params);
        $query=$pdo->prepare('SELECT s.*,a.username,p.user_id,'.$peer.' AS peer_id'.$from
            .' ORDER BY s.id DESC LIMIT 10 OFFSET '.($path==='/downloadgjmessage'?0:$offset));
        $query->execute($params); $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { return $path==='/downloadgjmessage'?'-1':'-2'; }
        if ($path==='/downloadgjmessage') {
            if (!$sent && $canWrite) {
                $pdo->prepare('UPDATE php7_messages SET is_read=1 WHERE id=? AND to_account_id=?')->execute(array($messageId,$aid));
                $rows[0]['is_read']=1;
            }
            return mucho7_message_wire($rows[0],$sent,true);
        }
        $entries=array(); foreach($rows as $row){ $entries[]=mucho7_message_wire($row,$sent,false); }
        return implode('|',$entries).'#'.(int)$count->fetchColumn().':'.$offset.':10';
    }
    return '-1';
}

function mucho7_social_write(PDO $pdo, $aid, $path, $data)
{
    $target=mucho7_number(isset($data['toAccountID'])?$data['toAccountID']:
        (isset($data['targetAccountID'])?$data['targetAccountID']:0),0,2147483647);
    $rid=mucho7_number(isset($data['requestID'])?$data['requestID']:0,0,2147483647);
    if ($path==='/readgjfriendrequest') {
        $query=$pdo->prepare('SELECT 1 FROM php7_friend_requests WHERE id=? AND to_account_id=?');
        $query->execute(array($rid,$aid)); if(!$query->fetchColumn()){return '-1';}
        $pdo->prepare('UPDATE php7_friend_requests SET is_read=1 WHERE id=? AND to_account_id=?')->execute(array($rid,$aid));
        return '1';
    }
    if ($path==='/deletegjmessages') {
        $id=mucho7_number(isset($data['messageID'])?$data['messageID']:0,0,2147483647);
        $sent=isset($data['isSender']) && (string)$data['isSender']==='1';
        $column=$sent?'is_sender_deleted':'is_receiver_deleted'; $owner=$sent?'account_id':'to_account_id';
        $query=$pdo->prepare('SELECT 1 FROM php7_messages WHERE id=? AND '.$owner.'=?');
        $query->execute(array($id,$aid)); if(!$query->fetchColumn()){return '-1';}
        $pdo->prepare('UPDATE php7_messages SET '.$column.'=1 WHERE id=? AND '.$owner.'=?')->execute(array($id,$aid));
        return '1';
    }
    if ($path==='/acceptgjfriendrequest') {
        $query=$pdo->prepare('SELECT account_id FROM php7_friend_requests WHERE id=? AND to_account_id=?');
        $query->execute(array($rid,$aid)); $target=(int)$query->fetchColumn();
    }
    if ($target<=0 || $target===$aid) { return '-1'; }
    $pdo->beginTransaction();
    try {
        if(!mucho7_social_lock($pdo,$aid,$target)){ $pdo->rollBack(); return '-1'; }
        $blocked=mucho7_social_blocked($pdo,$aid,$target);
        if ($path==='/uploadfriendrequest') {
            $comment=isset($data['comment'])?$data['comment']:'';
            $preferences=mucho7_details($pdo,$target);
            if ($blocked || !empty($preferences['friend_request_state'])
                || mucho7_social_friend($pdo,$aid,$target) || !mucho7_social_wire($comment,140,true)) {
                $pdo->rollBack(); return '-1';
            }
            $query=$pdo->prepare('SELECT 1 FROM php7_friend_requests WHERE
                (account_id=? AND to_account_id=?) OR (account_id=? AND to_account_id=?)');
            $query->execute(array($aid,$target,$target,$aid));
            if ($query->fetchColumn()) { $pdo->rollBack(); return '-1'; }
            $query=$pdo->prepare('SELECT COUNT(*) FROM php7_friend_requests WHERE account_id=? AND created_at>?');
            $query->execute(array($aid,gmdate('Y-m-d H:i:s',time()-600)));
            if ((int)$query->fetchColumn()>=10) { $pdo->rollBack(); return '-1'; }
            $pdo->prepare('INSERT INTO php7_friend_requests(account_id,to_account_id,comment) VALUES(?,?,?)')
                ->execute(array($aid,$target,$comment));
        } elseif ($path==='/acceptgjfriendrequest') {
            $query=$pdo->prepare('SELECT 1 FROM php7_friend_requests WHERE id=? AND account_id=? AND to_account_id=? FOR UPDATE');
            $query->execute(array($rid,$target,$aid));
            if ($blocked || !$query->fetchColumn()) { $pdo->rollBack(); return '-1'; }
            $pdo->prepare('INSERT IGNORE INTO php7_friends(account_id,friend_account_id) VALUES(?,?),(?,?)')
                ->execute(array($aid,$target,$target,$aid));
            $pdo->prepare('DELETE FROM php7_friend_requests WHERE id=?')->execute(array($rid));
        } elseif ($path==='/deletegjfriendrequests') {
            $sent=isset($data['isSender']) && (string)$data['isSender']==='1';
            $query=$pdo->prepare($sent?'DELETE FROM php7_friend_requests WHERE account_id=? AND to_account_id=?'
                :'UPDATE php7_friend_requests SET is_read=2 WHERE account_id=? AND to_account_id=? AND is_read<>2');
            $query->execute($sent?array($aid,$target):array($target,$aid));
            if($query->rowCount()===0){$pdo->rollBack();return '-1';}
        } elseif ($path==='/removegjfriend' || $path==='/blockgjuser') {
            if($path==='/removegjfriend' && !mucho7_social_friend($pdo,$aid,$target)){$pdo->rollBack();return '-1';}
            $pdo->prepare('DELETE FROM php7_friends WHERE (account_id=? AND friend_account_id=?)
                OR (account_id=? AND friend_account_id=?)')->execute(array($aid,$target,$target,$aid));
            if($path==='/blockgjuser') {
                $pdo->prepare('INSERT IGNORE INTO php7_blocks(account_id,blocked_account_id) VALUES(?,?)')->execute(array($aid,$target));
                $pdo->prepare('DELETE FROM php7_friend_requests WHERE (account_id=? AND to_account_id=?)
                    OR (account_id=? AND to_account_id=?)')->execute(array($aid,$target,$target,$aid));
            }
        } elseif ($path==='/unblockgjuser') {
            $pdo->prepare('DELETE FROM php7_blocks WHERE account_id=? AND blocked_account_id=?')->execute(array($aid,$target));
        } elseif ($path==='/uploadgjmessage') {
            $subject=isset($data['subject'])?$data['subject']:''; $body=isset($data['body'])?$data['body']:'';
            $preferences=mucho7_details($pdo,$target);
            $state=isset($preferences['message_state'])?(int)$preferences['message_state']:0;
            if($blocked || $state===2 || ($state===1 && !mucho7_social_friend($pdo,$aid,$target))
                || !mucho7_social_wire($subject,255) || !mucho7_social_wire($body,65535)) {
                $pdo->rollBack(); return '-1';
            }
            $query=$pdo->prepare('SELECT COUNT(*) FROM php7_messages WHERE account_id=? AND created_at>?');
            $query->execute(array($aid,gmdate('Y-m-d H:i:s',time()-60)));
            if((int)$query->fetchColumn()>=30){ $pdo->rollBack(); return '-1'; }
            $pdo->prepare('INSERT INTO php7_messages(account_id,to_account_id,subject,body) VALUES(?,?,?,?)')
                ->execute(array($aid,$target,$subject,$body));
        } else { $pdo->rollBack(); return '-1'; }
        $pdo->commit(); return '1';
    } catch (Exception $error) {
        if($pdo->inTransaction()){ $pdo->rollBack(); } throw $error;
    }
}

function mucho7_social_dispatch(PDO $pdo, $path, $data, $options)
{
    if (empty($options['enable_social']) || empty($options['enable_accounts'])) { return '-1'; }
    try{$pdo->query('SELECT account_id FROM php7_friend_requests LIMIT 0');}
    catch(PDOException $error){
        if($error->getCode()!=='42S22')throw $error;
        // Main's earlier native friend schema has different columns. Keep it
        // working without renaming, truncating or implicitly migrating user data.
        return mucho7_legacy_social_dispatch($pdo,$path,$data,$options);
    }
    $aid=mucho7_identity($pdo,$data); if($aid<=0){return '-1';}
    if(in_array($path,array('/getgjfriendrequests','/getgjuserlist','/getgjmessages','/downloadgjmessage'),true)) {
        return mucho7_social_read($pdo,$aid,$path,$data,!empty($options['enable_writes']));
    }
    return !empty($options['enable_writes'])?mucho7_social_write($pdo,$aid,$path,$data):'-1';
}
