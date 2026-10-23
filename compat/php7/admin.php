<?php
/** Native shared administration. No PHP8 classes or Composer. */
declare(strict_types=1);

function mucho7_admin_user(PDO $pdo, $id)
{
    if(function_exists('mucho7_admin_registry_ready') && mucho7_admin_registry_ready($pdo)){
        $row=mucho7_admin_identity($pdo,$id);
        if($row)unset($row['password_hash']);
        return $row;
    }
    $query=$pdo->prepare('SELECT a.account_id,a.username,r.code AS role FROM accounts a
        JOIN roles r ON r.id=a.role_id WHERE a.account_id=? AND a.is_active=1 AND a.is_banned=0');
    $query->execute(array($id)); $user=$query->fetch(PDO::FETCH_ASSOC);
    return $user && in_array($user['role'],array('owner','elder_moderator','moderator'),true)?$user:null;
}

function mucho7_admin_audit(PDO $pdo, $aid, $action, $target, $details = '')
{
    $pdo->prepare('INSERT INTO php7_admin_audit(account_id,action,target_id,details) VALUES(?,?,?,?)')
        ->execute(array($aid,substr($action,0,40),$target,substr($details,0,512)));
}

function mucho7_admin_has_owner(PDO $pdo)
{
    if(function_exists('mucho7_admin_registry_ready') && mucho7_admin_registry_ready($pdo)){
        return (int)$pdo->query("SELECT COUNT(*) FROM php7_admin_users u LEFT JOIN accounts a ON a.account_id=u.account_id WHERE u.role='owner' AND u.is_active=1 AND u.password_must_set=0 AND (u.account_id IS NULL OR (a.is_active=1 AND a.is_banned=0))")->fetchColumn()>0;
    }
    return (int)$pdo->query("SELECT COUNT(*) FROM accounts a JOIN roles r ON r.id=a.role_id
        WHERE r.code='owner' AND a.is_active=1 AND a.is_banned=0")->fetchColumn()>0;
}

function mucho7_admin_bootstrap_limit($root, $ip, $secret)
{
    $path=$root.'/config/php7-bootstrap-attempts.json';$handle=fopen($path,'c+');
    if(!$handle || !flock($handle,LOCK_EX)){throw new RuntimeException('Нет прав записи в config.');}
    @chmod($path,0600);
    try {
        $raw=stream_get_contents($handle,262144);$rows=json_decode($raw,true);if(!is_array($rows)){$rows=array();}
        foreach($rows as $key=>$row){if(!is_array($row) || (int)$row[0]<time()-600){unset($rows[$key]);}}
        $key=hash_hmac('sha256',(string)$ip,(string)$secret);
        if(isset($rows[$key]) && (int)$rows[$key][1]>=10){throw new RuntimeException('Слишком много попыток. Повтори через 10 минут.');}
        if(count($rows)>1000){$rows=array_slice($rows,-1000,null,true);}
        $rows[$key]=array(time(),isset($rows[$key])?(int)$rows[$key][1]+1:1);
        rewind($handle);ftruncate($handle,0);fwrite($handle,json_encode($rows));fflush($handle);
    } finally {flock($handle,LOCK_UN);fclose($handle);}
}

function mucho7_admin_initialize(PDO $pdo, $settings, $data, $root)
{
    $submitted=isset($data['db_password']) && is_string($data['db_password'])?$data['db_password']:'';
    if(empty($settings['password']) || strlen((string)$settings['password'])<8
        || !hash_equals((string)$settings['password'],$submitted)){throw new RuntimeException('Неверный пароль базы данных.');}
    $name=isset($data['userName']) && is_string($data['userName'])?$data['userName']:'';
    $pass=isset($data['password']) && is_string($data['password'])?$data['password']:'';
    $email=isset($data['email']) && is_string($data['email'])?$data['email']:'';
    if(!preg_match('/^[A-Za-z0-9_-]{1,20}$/D',$name) || strlen($pass)<8 || strlen($pass)>72
        || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)){throw new RuntimeException('Проверь поля аккаунта владельца.');}
    $lock=fopen($root.'/config/php7-install.lock','c');
    if(!$lock || !flock($lock,LOCK_EX)){throw new RuntimeException('Установка уже выполняется.');}
    @chmod($root.'/config/php7-install.lock',0600);
    try {
        $fresh=count($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN))===0;
        if($fresh) {
            $site=isset($data['site_url']) && is_string($data['site_url'])?rtrim(trim($data['site_url']),'/'):'';
            if(!preg_match('~^https://[A-Za-z0-9.-]+$~D',$site)){throw new RuntimeException('Укажи HTTPS-домен сервера без /mc.');}
            mucho7_create_schema($pdo);$settings['site_url']=$site;
        }
        $aid=mucho7_admin_bootstrap($pdo,$settings,$data);
        if(empty($settings['auth_pepper']) || strlen((string)$settings['auth_pepper'])<32
            || $settings['auth_pepper']==='GENERATED_BY_EXPERIMENTAL_INSTALLER'){$settings['auth_pepper']=bin2hex(random_bytes(32));}
        if($fresh && !empty($data['enable_features'])) {
            foreach(array('enable_accounts','enable_registration','enable_writes','enable_cloudsave','enable_social') as $key){$settings[$key]=true;}
        }
        mucho7_private_write($root.'/config/php7-local.php',"<?php\nreturn ".var_export($settings,true).";\n");
        if($fresh){mucho7_private_write($root.'/public/php7-mode.on','');}
        return $aid;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

function mucho7_admin_bootstrap(PDO $pdo, $settings, $data)
{
    // The operator proves knowledge of the existing PRIVATE database password.
    // No manually created setup token, public default password or first-user admin.
    $submitted=isset($data['db_password']) && is_string($data['db_password'])?$data['db_password']:'';
    $stored=isset($settings['password']) && is_string($settings['password'])?$settings['password']:'';
    if(strlen($stored)<8 || !hash_equals($stored,$submitted)) { throw new RuntimeException('Неверный пароль базы данных.'); }
    $name=isset($data['userName']) && is_string($data['userName'])?trim($data['userName']):'';
    $password=isset($data['password']) && is_string($data['password'])?$data['password']:'';
    $email=isset($data['email']) && is_string($data['email'])?trim($data['email']):'';
    if(!preg_match('/^[A-Za-z0-9_-]{1,20}$/D',$name) || strlen($password)<8 || strlen($password)>72
        || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Проверь логин, email и пароль аккаунта (8–72 байта).');
    }
    mucho7_upgrade_features($pdo);
    $pdo->beginTransaction();
    try {
        $owner=(int)$pdo->query("SELECT id FROM roles WHERE code='owner' FOR UPDATE")->fetchColumn();
        if($owner<=0 || mucho7_admin_has_owner($pdo)) { throw new RuntimeException('Владелец уже назначен. Войди в его аккаунт.'); }
        $query=$pdo->prepare('SELECT * FROM accounts WHERE username=? FOR UPDATE'); $query->execute(array($name));
        $row=$query->fetch(PDO::FETCH_ASSOC);
        if($row) {
            if((int)$row['is_banned']!==0 || (int)$row['is_active']!==1
                || !password_verify($password,$row['password_hash'])){throw new RuntimeException('Не удалось подтвердить аккаунт.');}
            $aid=(int)$row['account_id'];
            $pdo->prepare('UPDATE accounts SET role_id=? WHERE account_id=?')->execute(array($owner,$aid));
        } else {
            $pdo->prepare('INSERT INTO accounts(username,email,password_hash,gjp2_hash,role_id) VALUES(?,?,?,?,?)')
                ->execute(array($name,$email,password_hash($password,PASSWORD_BCRYPT,array('cost'=>12)),
                    password_hash(sha1($password.'mI29fmAnxgTs'),PASSWORD_BCRYPT,array('cost'=>12)),$owner));
            $aid=(int)$pdo->lastInsertId();
        }
        $pdo->prepare('INSERT IGNORE INTO profiles(account_id) VALUES(?)')->execute(array($aid));
        mucho7_admin_audit($pdo,$aid,'bootstrap_owner',$aid);
        $pdo->commit(); return $aid;
    } catch(Exception $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
}

function mucho7_admin_news($root, $data, $delete)
{
    if(is_file($root.'/config/php7-news-sql.on'))throw new RuntimeException('Use News Board to edit migrated news.');
    $filename=$root.'/config/php7-news.json';
    $lock=fopen($root.'/config/php7-news.lock','c');
    if(!$lock || !flock($lock,LOCK_EX)){throw new RuntimeException('Не удалось заблокировать файл новостей.');}
    @chmod($root.'/config/php7-news.lock',0600);
    try {
        if(is_file($root.'/config/php7-news-sql.on'))throw new RuntimeException('Use News Board to edit migrated news.');
        $source=mucho7_news_source($filename);
        if(isset($data['news_version']) && (string)$data['news_version']!==(string)$source['version']) {
            throw new RuntimeException('Новости уже изменены. Обнови страницу и повтори редактирование.');
        }
        $id=mucho7_number(isset($data['id'])?$data['id']:0,0,2147483647);
        $posts=$source['posts']; $max=0; $index=null;
        foreach($posts as $i=>$post){$max=max($max,(int)$post['id']);if((int)$post['id']===$id){$index=$i;}}
        if($delete) {
            if($index===null){throw new RuntimeException('Новость не найдена.');}
            array_splice($posts,$index,1);
        } else {
            $post=array('id'=>$id>0?$id:$max+1,
                'title'=>isset($data['title']) && is_string($data['title'])?trim($data['title']):'',
                'body'=>isset($data['body']) && is_string($data['body'])?trim($data['body']):'',
                'url'=>isset($data['url']) && is_string($data['url'])?trim($data['url']):'',
                'published_at'=>$index!==null?$posts[$index]['published_at']:gmdate('c'));
            try{mucho7_news_payload(array($post));}catch(RuntimeException $e){throw new RuntimeException('Проверь заголовок, текст и HTTPS-ссылку новости.');}
            if($index===null){array_unshift($posts,$post);}else{$posts[$index]=$post;}
            if(count($posts)>30){throw new RuntimeException('Можно опубликовать до 30 новостей. Удали одну из старых.');}
        }
        $source=array('version'=>max(1000001,(int)$source['version']+1),'posts'=>array_values($posts));
        $json=json_encode($source,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($json) || strlen($json)>131072){throw new RuntimeException('Новости занимают слишком много места. Сократи текст или удали старые записи.');}
        mucho7_private_write($filename,$json."\n");
        return $id;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}

function mucho7_admin_action(PDO $pdo, $aid, $action, $data, $root, $settings)
{
    $user=mucho7_admin_user($pdo,$aid);
    if(!$user){throw new RuntimeException('Нет доступа к панели.');}
    $owner=$user['role']==='owner';
    $can=function($permission)use($pdo,&$user){return function_exists('mucho7_admin_can')?mucho7_admin_can($pdo,$user,$permission):$user['role']==='owner';};
    $elder=$owner || $user['role']==='elder_moderator';
    $id=mucho7_number(isset($data['id'])?$data['id']:0,0,2147483647);
    if($action==='upgrade' && $owner){
        mucho7_upgrade_features($pdo); mucho7_admin_audit($pdo,$aid,'schema_upgrade',0); return;
    }
    if($action==='settings' && $owner) {
        $lock=fopen($root.'/config/php7-settings.lock','c');
        if(!$lock || !flock($lock,LOCK_EX)){throw new RuntimeException('Не удалось заблокировать настройки.');}
        try {
            $current=require $root.'/config/php7-local.php';
            $site=isset($data['site_url']) && is_string($data['site_url'])?rtrim(trim($data['site_url']),'/'):'';
            if(!preg_match('~^https://[A-Za-z0-9.-]+$~D',$site)){throw new RuntimeException('Укажи HTTPS-домен без пути /mc.');}
            $current['site_url']=$site;
            foreach(array('enable_accounts','enable_registration','enable_writes','enable_cloudsave','enable_social','enable_native_news') as $key){
                $current[$key]=isset($data[$key]) && (string)$data[$key]==='1';
            }
            if(empty($current['auth_pepper']) || strlen($current['auth_pepper'])<32){$current['auth_pepper']=bin2hex(random_bytes(32));}
            if($current['enable_social'] || $current['enable_cloudsave']){mucho7_upgrade_features($pdo);}
            mucho7_private_write($root.'/config/php7-local.php',"<?php\nreturn ".var_export($current,true).";\n");
        } finally {flock($lock,LOCK_UN);fclose($lock);}
        mucho7_admin_audit($pdo,$aid,'settings',0); return;
    }
    if(($action==='save_news' || $action==='delete_news') && $owner) {
        $id=mucho7_admin_news($root,$data,$action==='delete_news');
        mucho7_admin_audit($pdo,$aid,$action,$id); return;
    }
    $pdo->beginTransaction();
    try {
        if(function_exists('mucho7_admin_registry_ready') && mucho7_admin_registry_ready($pdo)){
            $pdo->query('SELECT version FROM php7_admin_registry_state WHERE id=1 FOR UPDATE')->fetchColumn();
            $user=mucho7_admin_user($pdo,$aid);if(!$user)throw new RuntimeException('Нет доступа к панели.');
            $owner=$user['role']==='owner';$elder=$owner||$user['role']==='elder_moderator';
        }
        if($action==='player' && $can('players.manage') && $id>0) {
            // Serialize owner changes so concurrent requests cannot remove the last owner.
            $pdo->query("SELECT id FROM roles WHERE code='owner' FOR UPDATE")->fetchColumn();
            $query=$pdo->prepare('SELECT a.*,r.code AS role FROM accounts a JOIN roles r ON r.id=a.role_id WHERE account_id=? FOR UPDATE');
            $query->execute(array($id)); $target=$query->fetch(PDO::FETCH_ASSOC);
            $role=isset($data['role']) && is_string($data['role'])?$data['role']:'user';
            $banned=isset($data['banned']) && (string)$data['banned']==='1'?1:0;
            if($target && !$owner && ($target['role']==='owner'||$role!==$target['role']))throw new RuntimeException('Only the owner may change game roles or manage owners.');
            if(!$target || !in_array($role,array('user','moderator','elder_moderator','owner'),true)) {throw new RuntimeException('Игрок или роль не найдены.');}
            $active=isset($data['original_ui'])?(isset($data['active'])?1:0):(int)$target['is_active'];
            $ownGameId=array_key_exists('game_account_id',$user)?(int)$user['game_account_id']:$aid;
            if($id===$ownGameId && ($banned || !$active || $role!=='owner')){throw new RuntimeException('Нельзя убрать собственный доступ владельца.');}
            if(function_exists('mucho7_admin_registry_ready') && mucho7_admin_registry_ready($pdo) && ($banned||!$active)){
                $q=$pdo->prepare("SELECT id FROM php7_admin_users WHERE account_id=? AND role='owner' AND is_active=1 AND password_must_set=0");$q->execute(array($id));
                if($q->fetchColumn() && (int)$pdo->query("SELECT COUNT(*) FROM php7_admin_users u LEFT JOIN accounts a ON a.account_id=u.account_id WHERE u.role='owner' AND u.is_active=1 AND u.password_must_set=0 AND (u.account_id IS NULL OR (a.is_active=1 AND a.is_banned=0))")->fetchColumn()<=1)throw new RuntimeException('На сервере должен остаться хотя бы один владелец.');
            }
            if($target['role']==='owner' && (int)$target['is_banned']===0 && ($banned || !$active || $role!=='owner')) {
                $count=(int)$pdo->query("SELECT COUNT(*) FROM accounts a JOIN roles r ON r.id=a.role_id WHERE r.code='owner' AND a.is_active=1 AND a.is_banned=0")->fetchColumn();
                if($count<=1){throw new RuntimeException('На сервере должен остаться хотя бы один владелец.');}
            }
            if(isset($data['original_ui'])){
                $name=isset($data['username']) && is_string($data['username'])?trim($data['username']):'';
                $email=isset($data['email']) && is_string($data['email'])?trim($data['email']):'';
                if(!preg_match('/^[A-Za-z0-9_-]{1,20}$/D',$name)||strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Проверь логин и email.');
                $pdo->prepare('UPDATE accounts SET username=?,email=?,is_active=? WHERE account_id=?')->execute(array($name,$email,$active,$id));
            }
            $pdo->prepare('UPDATE accounts SET is_banned=?,role_id=(SELECT id FROM roles WHERE code=?) WHERE account_id=?')->execute(array($banned,$role,$id));
        } elseif(in_array($action,array('profile-save','password-reset'),true) && $can($action==='password-reset'?'players.password_reset':'players.manage') && $id>0){
            $q=$pdo->prepare('SELECT a.account_id,r.code FROM accounts a JOIN roles r ON r.id=a.role_id WHERE account_id=? FOR UPDATE');$q->execute(array($id));$target=$q->fetch(PDO::FETCH_ASSOC);if(!$target)throw new RuntimeException('Аккаунт не найден.');
            if(!$owner && $target['code']==='owner')throw new RuntimeException('Only an owner may manage another owner.');
            if($action==='password-reset'){
                $pass=isset($data['new_password'])&&is_string($data['new_password'])?$data['new_password']:'';
                if(strlen($pass)<8||strlen($pass)>72||!isset($data['new_password_confirm'])||!is_string($data['new_password_confirm'])||!hash_equals($pass,$data['new_password_confirm']))throw new RuntimeException('Проверь пароль и подтверждение.');
                $pdo->prepare('UPDATE accounts SET password_hash=?,gjp2_hash=? WHERE account_id=?')->execute(array(password_hash($pass,PASSWORD_BCRYPT,array('cost'=>12)),password_hash(sha1($pass.'mI29fmAnxgTs'),PASSWORD_BCRYPT,array('cost'=>12)),$id));
            }else{
                $values=array();foreach(array('stars','moons','diamonds','secret_coins','user_coins','demons','creator_points') as $key)$values[]=mucho7_number(isset($data[$key])?$data[$key]:0,0,2147483647);
                $values[]=$id;$pdo->prepare('UPDATE profiles SET stars=?,moons=?,diamonds=?,secret_coins=?,user_coins=?,demons=?,creator_points=? WHERE account_id=?')->execute($values);
            }
        } elseif(in_array($action,array('rate_level','delete_level','restore_level','level_details'),true) && ($can($action==='rate_level'?'levels.rate':'levels.manage')||$elder) && $id>0) {
            $query=$pdo->prepare('SELECT account_id,coins_verified FROM levels WHERE level_id=? FOR UPDATE');$query->execute(array($id));
            $levelState=$query->fetch(PDO::FETCH_ASSOC);$author=$levelState?(int)$levelState['account_id']:0;if($author<=0){throw new RuntimeException('Уровень не найден.');}
            if($action==='rate_level') {
                $difficulty=mucho7_number(isset($data['difficulty'])?$data['difficulty']:0,0,60);
                if(!in_array($difficulty,array(0,10,20,30,40,50,60),true)){throw new RuntimeException('Неверная сложность.');}
                $stars=mucho7_number(isset($data['stars'])?$data['stars']:0,0,10);
                $epic=mucho7_number(isset($data['epic'])?$data['epic']:0,0,3);
                $featured=isset($data['featured']) && (string)$data['featured']==='1'?1:0;
                $demon=isset($data['demon']) && (string)$data['demon']==='1'?1:0;
                $auto=isset($data['auto']) && (string)$data['auto']==='1'?1:0;
                $coins=isset($data['coins_verified']) && (string)$data['coins_verified']==='1'?1:0;
                if(!empty($data['original_rating_ui']))$coins=(int)$levelState['coins_verified'];
                if($demon && $auto){throw new RuntimeException('Auto и Demon нельзя включить одновременно.');}
                $pdo->prepare('UPDATE levels SET difficulty=?,stars=?,featured=?,epic=?,demon=?,auto_level=?,coins_verified=?,
                    demon_difficulty=?,requested_stars=0,updated_at=CURRENT_TIMESTAMP WHERE level_id=?')
                    ->execute(array($difficulty,$stars,$featured,$epic,$demon,$auto,$coins,
                        $demon?mucho7_number(isset($data['demon_difficulty'])?$data['demon_difficulty']:0,0,6):0,$id));
            } elseif($action==='level_details'){
                $name=isset($data['name'])&&is_string($data['name'])?trim($data['name']):'';
                if($name===''||strlen($name)>64||preg_match('/[\x00-\x1f~|#:]/',$name))throw new RuntimeException('Проверь название уровня.');
                $pdo->prepare('UPDATE levels SET name=?,is_deleted=?,updated_at=CURRENT_TIMESTAMP WHERE level_id=?')->execute(array($name,isset($data['deleted'])?1:0,$id));
            } else {
                $pdo->prepare('UPDATE levels SET is_deleted=?,updated_at=CURRENT_TIMESTAMP WHERE level_id=?')
                    ->execute(array($action==='delete_level'?1:0,$id));
            }
            $query=$pdo->prepare('SELECT COALESCE(SUM((stars>0)+(featured>0)+epic),0) FROM levels WHERE account_id=? AND is_deleted=0');
            $query->execute(array($author));
            $pdo->prepare('UPDATE profiles SET creator_points=? WHERE account_id=?')->execute(array((int)$query->fetchColumn(),$author));
        } elseif($action==='delete_comment' && $can('comments.manage') && $id>0) {
            $pdo->prepare('DELETE FROM php7_comments WHERE id=?')->execute(array($id));
        } elseif(in_array($action,array('music-verify','music-unverify','music-delete'),true) && $can('music.manage') && $id>0){
            if($action==='music-delete')$pdo->prepare('DELETE FROM songs WHERE id=?')->execute(array($id));
            else $pdo->prepare('UPDATE songs SET is_verified=? WHERE id=?')->execute(array($action==='music-verify'?1:0,$id));
        } elseif($action==='song' && $can('music.manage')) {
            foreach(array('name','author_name','download_url') as $field){if(!isset($data[$field]) || !is_string($data[$field])){throw new RuntimeException('Заполни поля песни.');}}
            $url=trim($data['download_url']);$name=trim($data['name']);$author=trim($data['author_name']);
            if(strlen($url)>255 || stripos($url,'https://')!==0 || !filter_var($url,FILTER_VALIDATE_URL) || strpos($url,'#')!==false
                || $name==='' || strlen($name)>128 || $author==='' || strlen($author)>128
                || preg_match('/[\x00-\x1f~|#:]/',$name.$author)){throw new RuntimeException('Проверь название, автора и HTTPS-ссылку.');}
            $size=isset($data['size']) && is_scalar($data['size'])?(float)$data['size']:0;
            if(!is_finite($size) || $size<0 || $size>1024){throw new RuntimeException('Неверный размер песни.');}
            $pdo->prepare('INSERT INTO songs(id,name,author_name,download_url,size,is_verified) VALUES(?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE name=VALUES(name),author_name=VALUES(author_name),download_url=VALUES(download_url),size=VALUES(size),is_verified=VALUES(is_verified)')
                ->execute(array($id>0?$id:null,$name,$author,$url,$size,isset($data['verified'])?1:0));
            if($id===0)$id=(int)$pdo->lastInsertId();
        } else {throw new RuntimeException('Нет прав на это действие.');}
        mucho7_admin_audit($pdo,$aid,$action,$id);
        $pdo->commit();
    } catch(Exception $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
}

function mucho7_admin_tables()
{
    return array('roles','accounts','profiles','songs','levels','php7_auth_events',
        'php7_comments','php7_votes','php7_cloud_saves','php7_user_details','php7_friend_requests',
        'php7_friends','php7_blocks','php7_messages','php7_admin_audit','php7_downloads','php7_level_details');
}

function mucho7_admin_backup(PDO $pdo, $aid)
{
    $user=mucho7_admin_user($pdo,$aid);
    if(!$user || $user['role']!=='owner'){throw new RuntimeException('Только владелец может скачать резервную копию.');}
    $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if(array_diff(mucho7_admin_tables(),$tables)){throw new RuntimeException('Сначала обнови таблицы базы.');}
    $backupTables=array_merge(mucho7_admin_tables(),array_values(array_intersect(array('php7_admin_users','php7_admin_roles','php7_admin_role_permissions','php7_admin_registry_state','php7_admin_security','php7_admin_recovery_codes','mucho_news_posts','mucho_news_state','mucho_news_media','php7_friendships'),$tables)));
    mucho7_admin_audit($pdo,$aid,'database_export',0);
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="MuchoCore-shared-'.gmdate('Ymd-His').'.sql"');
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    try {
        echo "-- MuchoCore native shared backup (UTC)\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
        foreach($backupTables as $table) {
            $create=$pdo->query('SHOW CREATE TABLE `'.$table.'`');$row=$create->fetch(PDO::FETCH_NUM);$create->closeCursor();
            echo "\nDROP TABLE IF EXISTS `".$table."`;\n".$row[1].";\n";
            $query=$pdo->query('SELECT * FROM `'.$table.'`');
            while($row=$query->fetch(PDO::FETCH_ASSOC)) {
                $values=array();foreach($row as $value){$values[]=$value===null?'NULL':"X'".bin2hex((string)$value)."'";}
                echo 'INSERT INTO `'.$table.'` (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',$values).");\n";
            }
            $query->closeCursor();
        }
        // Pair the explicit consistent-snapshot START TRANSACTION with SQL
        // COMMIT. Old PHP7 mysqlnd can stall in PDO::commit after unbuffered
        // results, even though all cursors have been consumed and closed.
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);
        echo "SET FOREIGN_KEY_CHECKS=1;\n";$pdo->exec('COMMIT');
    } catch(Exception $e){if($pdo->inTransaction()){$pdo->rollBack();}throw $e;}
    finally{$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);}
}
