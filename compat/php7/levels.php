<?php
/** Native song discovery, owner actions and deduplicated download counters. */
declare(strict_types=1);

function mucho7_song(PDO $pdo, $data)
{
    $id=mucho7_number(isset($data['songID'])?$data['songID']:0,0,2147483647);
    $query=$pdo->prepare('SELECT * FROM songs WHERE id=? AND is_verified=1');$query->execute(array($id));
    $song=$query->fetch(PDO::FETCH_ASSOC);if(!$song){return '-1';}
    return implode('~|~',array(1,(int)$song['id'],2,mucho7_field($song['name'],128),
        3,(int)$song['author_id'],4,mucho7_field($song['author_name'],128),5,round((float)$song['size'],2),
        6,$song['youtube_video_id'],10,$song['download_url'],7,$song['youtube_channel_id'],8,0));
}

function mucho7_level_action(PDO $pdo, $data, $delete)
{
    $aid=mucho7_identity($pdo,$data);if($aid<=0){return '-1';}
    $id=mucho7_number(isset($data['levelID'])?$data['levelID']:0,0,2147483647);
    $query=$pdo->prepare('SELECT 1 FROM levels WHERE level_id=? AND account_id=? AND is_deleted=0');
    $query->execute(array($id,$aid));if(!$query->fetchColumn()){return '-1';}
    if($delete) {
        $pdo->prepare('UPDATE levels SET is_deleted=1 WHERE level_id=? AND account_id=?')->execute(array($id,$aid));
    } else {
        $encoded=isset($data['levelDesc'])?$data['levelDesc']:'';
        if(!is_string($encoded) || strlen($encoded)>8192 || !preg_match('/^[A-Za-z0-9+\/_-]*={0,2}$/D',$encoded)){return '-1';}
        $encoded=strtr($encoded,'-_','+/');$encoded.=str_repeat('=',(4-strlen($encoded)%4)%4);
        $plain=base64_decode($encoded,true);
        if(!is_string($plain) || preg_match('//u',$plain)!==1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$plain)){return '-1';}
        $pdo->prepare('UPDATE levels SET description=?,updated_at=CURRENT_TIMESTAMP WHERE level_id=? AND account_id=? AND is_deleted=0')
            ->execute(array($plain,$id,$aid));
    }
    return '1';
}

function mucho7_record_download(PDO $pdo, $id, $options)
{
    $ip=isset($options['ip'])?$options['ip']:'';$pepper=isset($options['auth_pepper'])?$options['auth_pepper']:'';
    if(!is_string($ip) || !filter_var($ip,FILTER_VALIDATE_IP) || !is_string($pepper) || strlen($pepper)<32){return;}
    $pdo->beginTransaction();
    try {
        $query=$pdo->prepare('INSERT IGNORE INTO php7_downloads(level_id,visitor_hash,downloaded_on) VALUES(?,?,?)');
        $query->execute(array($id,hash_hmac('sha256',$ip,$pepper),gmdate('Y-m-d')));
        if($query->rowCount()===1){$pdo->prepare('UPDATE levels SET downloads=downloads+1 WHERE level_id=? AND is_deleted=0')->execute(array($id));}
        $pdo->commit();
        if(random_int(1,100)===1){$pdo->prepare('DELETE FROM php7_downloads WHERE downloaded_on<?')->execute(array(gmdate('Y-m-d',time()-30*86400)));}
    } catch(Exception $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
}
