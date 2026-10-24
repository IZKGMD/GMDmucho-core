<?php
/** Bind the original admin form contracts to native shared transactions. */
declare(strict_types=1);
function mucho7_admin_ui_action(PDO $pdo,$aid,$action,$data,$root,$settings){
    if($action==='shared-worker-run'){
        $actor=mucho7_admin_user($pdo,$aid);
        if(!$actor||$actor['role']!=='owner'||!mucho7_admin_can($pdo,$actor,'system.manage'))throw new RuntimeException('Только владелец может выполнять очередь.');
        require_once __DIR__.'/../shared-worker.php';
        $result=mucho7_shared_worker($pdo,$root,$settings);
        mucho7_admin_audit($pdo,$aid,$action,0,json_encode($result));
        $_SESSION['shared_worker_result']=$result;return;
    }
    if(in_array($action,array('clan-save','clan-role','clan-kick','clan-transfer','clan-delete','gauntlet-save','gauntlet-delete','mappack-save','mappack-delete','muchoprofile-save','muchoprofile-token','relation-delete'),true)){
        if(!is_file($root.'/config/php7-complete.on'))throw new RuntimeException('Сначала заверши перенос ядра.');
        require_once $root.'/compat/php7/domain.php';mucho7_domain_configure($settings,$root,$pdo);
        $module=strpos($action,'clan-')===0?'clans':(strpos($action,'muchoprofile-')===0?'profiles':($action==='relation-delete'?'social':'contentpacks'));
        $db=$pdo;require mucho7_compiled_include($root.'/public/admin/actions/'.$module.'.php');return;
    }
    if($action==='comment-delete'&&is_file($root.'/config/php7-complete.on')){
        $actor=mucho7_admin_user($pdo,$aid);if(!mucho7_admin_can($pdo,$actor,'comments.manage'))throw new RuntimeException('Нет прав на это действие.');
        $table=isset($data['table'])&&is_string($data['table'])?$data['table']:'';
        if(!in_array($table,array('comments','account_comments'),true))throw new RuntimeException('Неверный тип комментария.');
        $id=mucho7_number(isset($data['id'])?$data['id']:0,0,2147483647);if(!$id)throw new RuntimeException('Неверный ID.');
        $pdo->beginTransaction();try{$pdo->prepare('DELETE FROM '.$table.' WHERE id=?')->execute(array($id));mucho7_admin_audit($pdo,$aid,$action,$id,$table);$pdo->commit();}catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}return;
    }
    if($action==='endpoint-test'){
        requirePermission('tools.endpoint_test');
        if(!is_file($root.'/config/php7-complete.on'))throw new RuntimeException('Сначала заверши перенос ядра.');
        $endpoint=isset($data['endpoint'])&&is_string($data['endpoint'])?trim($data['endpoint']):'';
        $payload=isset($data['payload'])&&is_string($data['payload'])?$data['payload']:'';
        if(!preg_match('~^/[A-Za-z0-9_/-]+(?:\.php)?$~D',$endpoint)||strlen($payload)>32768)throw new RuntimeException('Неверный адрес или слишком большой запрос.');
        parse_str($payload,$post);require_once $root.'/compat/php7/domain.php';
        $previous=$_POST;$_POST=$post;
        try{$response=mucho7_domain_handle($pdo,$endpoint,'POST',$post,$settings,$root);$_SESSION['endpoint_result']=substr($response->body,0,65536);}finally{$_POST=$previous;}
        mucho7_admin_audit($pdo,$aid,$action,0,$endpoint);return;
    }
    if(in_array($action,array('clientflag-save','clientrelease-save','monitor-alert-resolve','monitor-alert-resolve-all','monitor-cleanup','monitor-release-rollback'),true)){
        $client=strpos($action,'client')===0;requirePermission($client?'client.manage':'settings.manage');
        if(!is_file($root.'/config/php7-complete.on'))throw new RuntimeException('Сначала заверши перенос ядра.');
        require_once $root.'/compat/php7/domain.php';mucho7_domain_configure($settings,$root,$pdo);$db=$pdo;
        require mucho7_compiled_include($root.'/public/admin/'.($client?'client-features-module':'security-monitoring-module').'.php');return;
    }
    if($action==='dbbackup-create'){
        require_once __DIR__.'/../admin-backups.php';
        mucho7_admin_backup_action($pdo,$aid,$action,$data);return;
    }
    if(in_array($action,array('admin-create','admin-toggle','admin-role','role-create','role-update','role-delete'),true)){
        mucho7_admin_registry_action($pdo,$aid,$action,$data,$settings);return;
    }
    if(in_array($action,array('2fa-generate','2fa-enable','2fa-disable','2fa-cancel','recovery-generate'),true)){
        mucho7_admin_security_action($pdo,$aid,$action,$data,$settings);return;
    }
    if(in_array($action,array('news-save','news-toggle','news-delete'),true)) {
        $actor=mucho7_admin_user($pdo,$aid);
        if(!$actor || $actor['role']!=='owner')throw new RuntimeException('Only the GDPS owner may edit news.');
        mucho7_news_install($pdo,$root);
        $db=$pdo;$files=$_FILES;
        require __DIR__.'/news-actions.php';
        return;
    }
    if($action==='settings-save'){
        $user=mucho7_admin_user($pdo,$aid);if(!mucho7_admin_can($pdo,$user,$action==='message-delete'?'messages.manage':($action==='music-upload'?'music.manage':'settings.manage')))throw new RuntimeException('Нет прав на это действие.');
        $name=isset($data['server_name'])&&is_string($data['server_name'])?trim($data['server_name']):'';
        $by=isset($data['server_by_name'])&&is_string($data['server_by_name'])?trim($data['server_by_name']):'';
        $url=isset($data['social_url'])&&is_string($data['social_url'])?trim($data['social_url']):'';
        if($name===''||strlen($name)>64||strlen($by)>64||strlen($url)>512||($url!==''&&(stripos($url,'https://')!==0||!filter_var($url,FILTER_VALIDATE_URL))))throw new RuntimeException('Проверь название, подпись и HTTPS-ссылку.');
        $lock=fopen($root.'/config/php7-settings.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Не удалось заблокировать настройки.');
        try{
            $current=require $root.'/config/php7-local.php';$current['server_name']=$name;$current['server_by_name']=$by;$current['social_url']=$url;
            foreach(array('maintenance'=>'maintenance.flag','registrations_disabled'=>'registrations-disabled.flag') as $field=>$filename){
                $path=$root.'/config/'.$filename;
                if(isset($data[$field]))mucho7_private_write($path,'1');
                elseif(is_file($path)&&!unlink($path))throw new RuntimeException('Не удалось обновить флаг сервера.');
            }
            mucho7_private_write($root.'/config/php7-local.php',"<?php\nreturn ".var_export($current,true).";\n");
        }finally{flock($lock,LOCK_UN);fclose($lock);}
        mucho7_admin_audit($pdo,$aid,'settings-save',0);return;
    }
    if($action==='message-delete'){
        $user=mucho7_admin_user($pdo,$aid);if(!mucho7_admin_can($pdo,$user,$action==='message-delete'?'messages.manage':($action==='music-upload'?'music.manage':'settings.manage')))throw new RuntimeException('Нет прав на это действие.');
        $id=mucho7_number(isset($data['id'])?$data['id']:0,1,2147483647);
        $pdo->beginTransaction();try{$pdo->prepare('DELETE FROM '.(is_file($root.'/config/php7-complete.on')?'messages':'php7_messages').' WHERE id=?')->execute(array($id));mucho7_admin_audit($pdo,$aid,$action,$id);$pdo->commit();}catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}return;
    }
    if($action==='comment-delete')$action='delete_comment';
    if($action==='account-save'){
        $action='player';$data['original_ui']=1;
        if(isset($data['banned']))$data['banned']='1';
    }elseif($action==='level-details-save')$action='level_details';
    elseif($action==='level-rate-save'){
        $profiles=array('unrated'=>array(0,0,0),'auto'=>array(0,0,1),'easy'=>array(10,0,0),'normal'=>array(20,0,0),'hard'=>array(30,0,0),'harder'=>array(40,0,0),'insane'=>array(50,0,0),
            'easy-demon'=>array(60,3,0),'medium-demon'=>array(60,4,0),'hard-demon'=>array(60,0,0),'insane-demon'=>array(60,5,0),'extreme-demon'=>array(60,6,0));
        $profile=isset($data['difficulty_profile'])&&is_string($data['difficulty_profile'])?$data['difficulty_profile']:'';
        if(!isset($profiles[$profile]))throw new RuntimeException('Неверная сложность.');
        $feature=mucho7_number(isset($data['feature'])?$data['feature']:0,0,4);
        $data['difficulty']=$profiles[$profile][0];$data['demon_difficulty']=$profiles[$profile][1];
        $data['featured']=$feature>0?'1':'0';$data['epic']=max(0,$feature-1);
        $data['demon']=substr($profile,-6)==='-demon'?'1':'0';$data['auto']=$profiles[$profile][2]?'1':'0';
        $data['coins_verified']=isset($data['coins_verified'])?'1':'0';$action='rate_level';
        $data['original_rating_ui']=true;
    }elseif($action==='music-upload'){
        $user=mucho7_admin_user($pdo,$aid);if(!mucho7_admin_can($pdo,$user,$action==='message-delete'?'messages.manage':($action==='music-upload'?'music.manage':'settings.manage')))throw new RuntimeException('Нет прав на это действие.');
        $file=isset($_FILES['music_file'])?$_FILES['music_file']:null;
        if(!$file||!is_array($file)||$file['error']!==UPLOAD_ERR_OK||$file['size']<=0||$file['size']>20*1024*1024||!is_uploaded_file($file['tmp_name']))throw new RuntimeException('Не удалось загрузить MP3. Лимит: 20 МиБ и ограничения хостинга.');
        $header=file_get_contents($file['tmp_name'],false,null,0,3);
        if(substr($header,0,3)!=='ID3' && !(strlen($header)>=2&&ord($header[0])===255&&(ord($header[1])&224)===224))throw new RuntimeException('Файл не похож на MP3.');
        $directory=$root.'/public/assets/music';if(!is_dir($directory)&&!mkdir($directory,0755,true))throw new RuntimeException('Нет прав записи аудиофайлов.');
        $filename=bin2hex(random_bytes(18)).'.mp3';$path=$directory.'/'.$filename;
        if(!move_uploaded_file($file['tmp_name'],$path))throw new RuntimeException('Не удалось сохранить MP3.');
        @chmod($path,0644);
        $script=isset($_SERVER['SCRIPT_NAME'])?$_SERVER['SCRIPT_NAME']:'/mc/admin.php';
        $mount=rtrim(str_replace('\\','/',dirname($script)),'/.');if(substr($script,-10)!=='/admin.php')$mount=rtrim(dirname($mount),'/.');
        $song=array('id'=>0,'name'=>isset($data['title'])?$data['title']:'','author_name'=>isset($data['artist'])?$data['artist']:'',
            'download_url'=>rtrim($settings['site_url'],'/').$mount.'/public/assets/music/'.$filename,'size'=>$file['size']/1048576,'verified'=>1);
        try{mucho7_admin_action($pdo,$aid,'song',$song,$root,$settings);}catch(Exception $e){@unlink($path);throw $e;}
        return;
    }
    mucho7_admin_action($pdo,$aid,$action,$data,$root,$settings);
}
