<?php
/**
 * Experimental native PHP7 authenticated level upload.
 * No unverified account/IP bypass. Only the owner can update a level.
 * Bounded uploads up to 8 MiB fit the shared host's configured POST budget.
 */
declare(strict_types=1);
function mucho7_upload(PDO $pdo, $data)
{
    $info=isset($data['levelInfo'])?$data['levelInfo']:'';
    if(!is_string($info) || strlen($info)>65535 || preg_match('/[:|#~\x00\r\n]/',$info)){return '-1';}
    $pdo->beginTransaction();
    try {
        $id=mucho7_upload_level($pdo,$data);
        if($id==='-1'){$pdo->rollBack();return '-1';}
        $pdo->prepare('REPLACE INTO php7_level_details(level_id,level_info) VALUES(?,?)')->execute(array((int)$id,$info));
        $pdo->commit();return $id;
    } catch(Exception $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
}

function mucho7_upload_level(PDO $pdo, $data)
{
    if (!function_exists('mucho7_authenticate')) { return '-1'; }
    $aid = mucho7_number(isset($data['accountID']) ? $data['accountID'] : 0,0,2147483647);
    $secret = mucho7_credential($data);
    if ($aid <= 0 || !mucho7_authenticate($pdo,$aid,$secret)) { return '-1'; }
    $name = isset($data['levelName']) && is_string($data['levelName'])
        ? trim($data['levelName']) : '';
    $level = isset($data['levelString']) && is_string($data['levelString'])
        ? $data['levelString'] : '';
    if ($name === '' || strlen($name)>64 ||
        preg_match('/[\x00-\x1f\x7f]/', $name) || preg_match('//u',$name)!==1 ||
        preg_match('/[:|#~]/',$name) || $level === '' || strlen($level)>8*1024*1024
        || preg_match('/[:|#~\x00\r\n]/',$level)) { return '-1'; }
    $gv = mucho7_number(isset($data['gameVersion']) ? $data['gameVersion'] : 22, 22, 22);
    $bv = mucho7_number(isset($data['binaryVersion']) ? $data['binaryVersion'] : 0, 0, 1000);
    if ($gv !== 22 || $bv < 47) { return '-1'; }
    $id = mucho7_number(isset($data['levelID']) ? $data['levelID'] : 0, 0, 2000000000);
    $encoded = isset($data['levelDesc']) && is_string($data['levelDesc'])?$data['levelDesc']:'';
    if(strlen($encoded)>8192){return '-1';}
    $decoded = '';
    if ($encoded !== '') {
        if (!preg_match('/^[A-Za-z0-9+\/_-]*={0,2}$/D', $encoded)) { return '-1'; }
        $normalized = strtr($encoded, '-_', '+/');
        $normalized .= str_repeat('=', (4-strlen($normalized)%4)%4);
        $plain = base64_decode($normalized,true);
        if ($plain === false || preg_match('//u', $plain) !== 1 ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $plain)) {
            return '-1';
        }
        $decoded = $plain;
    }
    $metadata=array();
    foreach(array('extraString'=>array('extra_string',65535),'settingsString'=>array('settings_string',65535),
        'songIDs'=>array('song_ids',8192),'sfxIDs'=>array('sfx_ids',8192)) as $input=>$field) {
        $value=isset($data[$input])?$data[$input]:'';
        if(!is_string($value) || strlen($value)>$field[1] || preg_match('/[:|#~\x00\r\n]/',$value)){return '-1';}
        if(($input==='songIDs' || $input==='sfxIDs') && $value!=='' && !preg_match('/^\d+(?:,\d+)*$/D',$value)){return '-1';}
        $metadata[$field[0]]=$value;
    }
    $copy=isset($data['password']) && is_string($data['password'])?$data['password']:'0';
    if(!preg_match('/^\d{1,7}$/D',$copy)){return '-1';}
    $base = array(
        ':aid'=>$aid, ':name'=>$name, ':description'=>$decoded,
        ':data'=>$level,
        ':version'=>mucho7_number(isset($data['levelVersion']) ? $data['levelVersion'] : 1,1,1000000),
        ':gv'=>$gv,
        ':len'=>mucho7_number(isset($data['levelLength']) ? $data['levelLength'] : 0,0,10),
        ':track'=>mucho7_number(isset($data['audioTrack']) ? $data['audioTrack'] : 0,0,1000000),
        ':song'=>mucho7_number(isset($data['songID']) ? $data['songID'] : 0,0,2000000000),
        ':objects'=>mucho7_number(isset($data['objects']) ? $data['objects'] : 0,0,5000000),
        ':coins'=>mucho7_number(isset($data['coins']) ? $data['coins'] : 0,0,1000),
        ':requested'=>mucho7_number(isset($data['requestedStars']) ? $data['requestedStars'] : 0,0,100),
        ':unlisted'=>!empty($data['unlisted1']) && (string)$data['unlisted1']==='1' ? 1 : 0,
        ':unlisted2'=>!empty($data['unlisted2']) && (string)$data['unlisted2']==='1' ? 1 : 0,
        ':copy'=>$copy,
        ':ldm'=>isset($data['ldm']) && (string)$data['ldm']==='1' ? 1 : 0
    );
    foreach($metadata as $column=>$value){$base[':'.$column]=$value;}
    $base[':original']=mucho7_number(isset($data['original'])?$data['original']:0,0,2147483647);
    $base[':two_player']=isset($data['twoPlayer']) && (string)$data['twoPlayer']==='1'?1:0;
    $base[':wt']=mucho7_number(isset($data['wt'])?$data['wt']:0,0,2147483647);
    $base[':wt2']=mucho7_number(isset($data['wt2'])?$data['wt2']:0,0,2147483647);
    $base[':ts']=mucho7_number(isset($data['ts'])?$data['ts']:0,0,2147483647);
    if ($id > 0) {
        // This must be a single permissioned UPDATE, never an upsert by id.
        $sql = 'UPDATE levels SET name=:name,description=:description,
             level_data=:data,level_version=:version,game_version=:gv,
             length=:len,audio_track=:track,song_id=:song,object_count=:objects,
             coins=:coins,requested_stars=:requested,is_unlisted=:unlisted,
             unlisted2=:unlisted2,copy_password=:copy,ldm=:ldm,
             extra_string=:extra_string,settings_string=:settings_string,song_ids=:song_ids,sfx_ids=:sfx_ids,
             original_level_id=:original,two_player=:two_player,wt=:wt,wt2=:wt2,ts=:ts,
             updated_at=CURRENT_TIMESTAMP
             WHERE level_id=:id AND account_id=:aid AND is_deleted=0';
        $query = $pdo->prepare($sql);
        $base[':id'] = $id;
        $query->execute($base);
        if ($query->rowCount() === 1) { return (string)$id; }
        // Updating identical data is still success, but only if the row
        // is owned by this authenticated account.
        $check = $pdo->prepare(
            'SELECT 1 FROM levels WHERE level_id=:id AND account_id=:aid
             AND is_deleted=0 LIMIT 1'
        );
        $check->execute(array(':id'=>$id,':aid'=>$aid));
        return $check->fetchColumn() !== false ? (string)$id : '-1';
    }
    $query = $pdo->prepare(
        'INSERT INTO levels (
          account_id,name,description,level_data,level_version,game_version,
          length,audio_track,song_id,object_count,coins,requested_stars,
          is_unlisted,unlisted2,copy_password,ldm,extra_string,settings_string,song_ids,sfx_ids,
          original_level_id,two_player,wt,wt2,ts
         ) VALUES (
          :aid,:name,:description,:data,:version,:gv,:len,:track,:song,
          :objects,:coins,:requested,:unlisted,:unlisted2,:copy,:ldm,:extra_string,:settings_string,:song_ids,:sfx_ids,
          :original,:two_player,:wt,:wt2,:ts
         )'
    );
    $query->execute($base);
    $newId=(int)$pdo->lastInsertId();
    return $newId > 0 ? (string)$newId : '-1';
}
