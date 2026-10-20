<?php
/**
 * Experimental native PHP7 authenticated level upload.
 * No unverified account/IP bypass. Only the owner can update a level.
 * Small payload cap is deliberate for 128 MB shared-hosting PHP limits.
 * Does not claim full GD 2.209 editor/cloudsave compatibility.
 */
declare(strict_types=1);
function mucho7_upload(PDO $pdo, $data)
{
    if (!function_exists('mucho7_authenticate')) { return '-1'; }
    $aid = mucho7_number(isset($data['accountID']) ? $data['accountID'] : 0,0,2147483647);
    $secret = '';
    if (isset($data['gjp2']) && is_string($data['gjp2'])) {
        $secret = $data['gjp2'];
    } elseif (isset($data['gjp']) && is_string($data['gjp'])) {
        $secret = $data['gjp'];
    }
    if ($aid <= 0 || !mucho7_authenticate($pdo,$aid,$secret)) { return '-1'; }
    $name = isset($data['levelName']) && is_string($data['levelName'])
        ? trim($data['levelName']) : '';
    $level = isset($data['levelString']) && is_string($data['levelString'])
        ? $data['levelString'] : '';
    if ($name === '' || strlen($name)>64 ||
        preg_match('/[\x00-\x1f\x7f]/', $name) ||
        $level === '' || strlen($level)>200*1024) { return '-1'; }
    $gv = mucho7_number(isset($data['gameVersion']) ? $data['gameVersion'] : 22, 22, 22);
    $bv = mucho7_number(isset($data['binaryVersion']) ? $data['binaryVersion'] : 0, 0, 1000);
    if ($gv !== 22 || $bv < 47) { return '-1'; }
    $id = mucho7_number(isset($data['levelID']) ? $data['levelID'] : 0, 0, 2000000000);
    $encoded = isset($data['levelDesc']) && is_string($data['levelDesc'])
        ? substr($data['levelDesc'],0,8192) : '';
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
        ':copy'=>isset($data['password']) && is_string($data['password'])
            ? substr($data['password'],0,64) : '0',
        ':ldm'=>isset($data['ldm']) && (string)$data['ldm']==='1' ? 1 : 0
    );
    if ($id > 0) {
        // This must be a single permissioned UPDATE, never an upsert by id.
        $sql = 'UPDATE levels SET name=:name,description=:description,
             level_data=:data,level_version=:version,game_version=:gv,
             length=:len,audio_track=:track,song_id=:song,object_count=:objects,
             coins=:coins,requested_stars=:requested,is_unlisted=:unlisted,
             unlisted2=:unlisted2,copy_password=:copy,ldm=:ldm,
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
          is_unlisted,unlisted2,copy_password,ldm
         ) VALUES (
          :aid,:name,:description,:data,:version,:gv,:len,:track,:song,
          :objects,:coins,:requested,:unlisted,:unlisted2,:copy,:ldm
         )'
    );
    $query->execute($base);
    $newId=(int)$pdo->lastInsertId();
    return $newId > 0 ? (string)$newId : '-1';
}
