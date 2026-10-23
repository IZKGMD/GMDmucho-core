<?php
/** Authenticated score/appearance updates; returns GD's userID, never a role. */
declare(strict_types=1);

function mucho7_update_score(PDO $pdo, $data)
{
    $aid = mucho7_identity($pdo, $data);
    if ($aid <= 0) { return '-1'; }
    $mapping = array(
        'stars'=>array('stars',10000000), 'moons'=>array('moons',10000000),
        'diamonds'=>array('diamonds',100000000), 'demons'=>array('demons',1000000),
        'secret_coins'=>array('coins',1000000), 'user_coins'=>array('userCoins',1000000),
        'icon_id'=>array(isset($data['icon']) ? 'icon' : 'accIcon',65535),
        'icon_type'=>array('iconType',32), 'color1'=>array('color1',65535),
        'color2'=>array('color2',65535),
        'glow'=>array(isset($data['accGlow']) ? 'accGlow' : 'glow',1)
    );
    $sets = array(); $params = array(':aid'=>$aid);
    foreach ($mapping as $column=>$definition) {
        if (!isset($data[$definition[0]])) { continue; }
        $value = $data[$definition[0]];
        if (!is_scalar($value) || !preg_match('/^\d{1,10}$/D', (string)$value)) { return '-1'; }
        $params[':'.$column] = min($definition[1], (int)$value);
        $sets[] = $column . '=:' . $column;
    }
    $extra=array();
    foreach(array('ship'=>'accShip','ball'=>'accBall','ufo'=>'accBird','wave'=>'accDart',
        'robot'=>'accRobot','spider'=>'accSpider','swing'=>'accSwing','jetpack'=>'accJetpack',
        'explosion'=>'accExplosion','special'=>'special') as $field=>$input) {
        if(isset($data[$input])) {
            if(!is_scalar($data[$input]) || !preg_match('/^\d{1,5}$/D',(string)$data[$input])){return '-1';}
            $extra[$field]=min($field==='special'?1:65535,(int)$data[$input]);
        }
    }
    if(isset($data['color3'])) {
        if(!is_scalar($data['color3']) || !preg_match('/^-?\d{1,5}$/D',(string)$data['color3'])){return '-1';}
        $extra['color3']=max(-1,min(32767,(int)$data['color3']));
    }
    foreach(array('demon_info'=>'dinfo','star_info'=>'sinfo','platformer_info'=>'pinfo') as $field=>$input) {
        if(isset($data[$input])) {
            if(!is_string($data[$input]) || strlen($data[$input])>4096
                || preg_match('/[^0-9,;-]/',$data[$input])){return '-1';}
            $extra[$field]=$data[$input];
        }
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT account_id FROM accounts WHERE account_id=? FOR UPDATE')->execute(array($aid));
        if($sets) {
            $query=$pdo->prepare('UPDATE profiles SET '.implode(',',$sets)
                .',updated_at=CURRENT_TIMESTAMP WHERE account_id=:aid'); $query->execute($params);
        }
        if($extra){mucho7_store_details($pdo,$aid,$extra);}
        $pdo->commit();
    } catch(Exception $e){ if($pdo->inTransaction()){$pdo->rollBack();} throw $e; }
    $find = $pdo->prepare('SELECT user_id FROM profiles WHERE account_id=?');
    $find->execute(array($aid));
    $uid = (int)$find->fetchColumn();
    return $uid > 0 ? (string)$uid : '-1';
}

function mucho7_details(PDO $pdo, $aid)
{
    $query=$pdo->prepare('SELECT payload FROM php7_user_details WHERE account_id=?');
    $query->execute(array($aid)); $payload=$query->fetchColumn();
    $parsed=$payload!==false?json_decode($payload,true):array();
    return is_array($parsed)?$parsed:array();
}

function mucho7_store_details(PDO $pdo, $aid, $patch)
{
    // Call under the owner's account row lock to avoid concurrent lost updates.
    $details=array_merge(mucho7_details($pdo,$aid),$patch);
    $payload=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($payload) || strlen($payload)>50000){throw new RuntimeException('Invalid profile details');}
    $pdo->prepare('INSERT INTO php7_user_details(account_id,payload) VALUES(?,?)
        ON DUPLICATE KEY UPDATE payload=VALUES(payload)')->execute(array($aid,$payload));
}

function mucho7_enrich_users(PDO $pdo, $rows)
{
    if(!$rows){return $rows;}
    $ids=array(); foreach($rows as $row){$ids[(int)$row['account_id']]=(int)$row['account_id'];}
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $query=$pdo->prepare('SELECT a.account_id,d.payload,r.code AS role_code FROM accounts a
        LEFT JOIN php7_user_details d ON d.account_id=a.account_id
        LEFT JOIN roles r ON r.id=a.role_id WHERE a.account_id IN ('.$marks.')');
    $query->execute(array_values($ids)); $extra=array();
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $parsed=is_string($row['payload'])?json_decode($row['payload'],true):array();
        $extra[$row['account_id']]=is_array($parsed)?$parsed:array();
        $extra[$row['account_id']]['role_code']=$row['role_code'];
    }
    foreach($rows as &$row) {
        // Only known presentation fields can flow from JSON into public wire.
        foreach(array('ship','ball','ufo','wave','robot','spider','swing','jetpack',
            'explosion','special','color3','demon_info','star_info','platformer_info',
            'message_state','friend_request_state','comment_history_state',
            'youtube','twitter','twitch','discord','instagram','tiktok','custom_link','role_code') as $key) {
            if(isset($extra[$row['account_id']][$key])){$row[$key]=$extra[$row['account_id']][$key];}
        }
    }
    unset($row); return $rows;
}

function mucho7_update_settings(PDO $pdo, $data)
{
    $aid=mucho7_identity($pdo,$data); if($aid<=0){return '-1';}
    $patch=array();
    foreach(array('mS'=>array('message_state',2),'frS'=>array('friend_request_state',1),
        'cS'=>array('comment_history_state',2)) as $input=>$field) {
        if(isset($data[$input])) {
            if(!is_scalar($data[$input]) || !preg_match('/^[0-2]$/D',(string)$data[$input])
                || (int)$data[$input]>$field[1]){return '-1';}
            $patch[$field[0]]=(int)$data[$input];
        }
    }
    foreach(array('yt'=>'youtube','twitter'=>'twitter','twitch'=>'twitch','discord'=>'discord',
        'instagram'=>'instagram','tiktok'=>'tiktok','custom'=>'custom_link') as $input=>$field) {
        if(!isset($data[$input])){continue;}
        if(!is_string($data[$input]) || strlen($data[$input])>255
            || preg_match('/[\x00-\x1f\x7f:|#~]/',$data[$input])){return '-1';}
        // The GD fields contain handles/encoded links, never protocol separators.
        $patch[$field]=trim($data[$input]);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT account_id FROM accounts WHERE account_id=? FOR UPDATE')->execute(array($aid));
        mucho7_store_details($pdo,$aid,$patch); $pdo->commit(); return '1';
    } catch(Exception $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
}
