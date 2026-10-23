<?php
/** Owner-only, resumable installation of a reviewed PHP7 build. PHP 7.0+. */
declare(strict_types=1);

function mucho7_core_lock($root, $exclusive)
{
    $path=$root.'/config/php7-runtime.lock';
    if(is_link($path))throw new RuntimeException('Invalid runtime lock.');
    $handle=fopen($path,'c');
    if(!$handle || !flock($handle,($exclusive?LOCK_EX:LOCK_SH)|LOCK_NB)){
        if(is_resource($handle))fclose($handle);
        throw new RuntimeException('Сервер обрабатывает другой запрос. Повтори через несколько секунд.');
    }
    @chmod($path,0600);
    return $handle;
}

function mucho7_core_pack_path($path)
{
    if(!is_string($path)||strlen($path)>240||strpos($path,'..')!==false||strpos($path,'\\')!==false)return false;
    return (bool)preg_match('~^(?:domain-(?:polyfills|schema|data|runtime)\.php|core/(?:autoload\.php|sources\.json|config/mucho_v71\.php|(?:src|database/migrations|public/admin/(?:pages|actions))/[A-Za-z0-9_/-]+\.php|public/admin/(?:client-features-module|security-monitoring-module)\.php)|vendor/[A-Za-z0-9_/.-]+\.(?:php|json|md|txt)|vendor/(?:phpseclib|constant-time-encoding)/LICENSE)$~D',$path);
}

function mucho7_core_install_pack($root)
{
    $file=$root.'/config/php7-core-update.pack';
    $metadataFile=$root.'/config/php7-core-update.json';
    if(!is_file($file)||!is_file($metadataFile))throw new RuntimeException('Сначала загрузи подготовленную сборку в config.');
    $metadata=json_decode((string)file_get_contents($metadataFile),true);
    if(!is_array($metadata)||!isset($metadata['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$metadata['sha256']))throw new RuntimeException('Некорректный паспорт сборки.');
    if(filesize($file)>8*1024*1024||!hash_equals($metadata['sha256'],hash_file('sha256',$file)))throw new RuntimeException('Контрольная сумма сборки не совпадает. Загрузи файл заново.');
    $raw=gzdecode((string)file_get_contents($file),32*1024*1024);
    $pack=is_string($raw)?json_decode($raw,true):null;unset($raw);
    if(!is_array($pack)||!isset($pack['format'],$pack['files'])||$pack['format']!==1||!is_array($pack['files'])||count($pack['files'])>1500)throw new RuntimeException('Формат сборки не поддерживается.');
    $contents=array();$total=0;
    foreach($pack['files'] as $entry){
        if(!is_array($entry)||!isset($entry['path'],$entry['sha256'],$entry['data'])||!mucho7_core_pack_path($entry['path'])||isset($contents[$entry['path']]))throw new RuntimeException('Недопустимый путь в сборке.');
        $bytes=base64_decode($entry['data'],true);
        if(!is_string($bytes)||strlen($bytes)>2*1024*1024||!hash_equals($entry['sha256'],hash('sha256',$bytes)))throw new RuntimeException('Повреждён файл сборки.');
        $total+=strlen($bytes);if($total>20*1024*1024)throw new RuntimeException('Сборка превышает лимит распаковки.');
        $contents[$entry['path']]=$bytes;
    }
    foreach(array('core/autoload.php','core/sources.json','core/src/Core/Application.php','vendor/phpseclib/bootstrap.php','domain-polyfills.php','domain-schema.php','domain-data.php','domain-runtime.php') as $required){
        if(!isset($contents[$required]))throw new RuntimeException('Неполная сборка ядра.');
    }
    $directory=$root.'/compat/php7/builds/'.$metadata['sha256'];
    foreach(array($root.'/compat/php7/builds',$directory) as $parent){
        if(is_link($parent))throw new RuntimeException('Каталог сборок не должен быть ссылкой.');
        if(!is_dir($parent)&&!mkdir($parent,0700,true))throw new RuntimeException('Нет прав записи сборки.');
    }
    foreach($contents as $path=>$bytes){
        $target=$directory.'/'.$path;$parent=dirname($target);
        for($part=$parent;$part!==$directory;$part=dirname($part))if(is_link($part))throw new RuntimeException('Недопустимая ссылка в каталоге сборки.');
        if(is_link($target))throw new RuntimeException('Недопустимая ссылка в сборке.');
        if(!is_dir($parent)&&!mkdir($parent,0700,true))throw new RuntimeException('Нет прав записи файлов сборки.');
        if(is_file($target)){
            if(!hash_equals(hash('sha256',$bytes),hash_file('sha256',$target)))throw new RuntimeException('Установленная сборка была изменена.');
        }else mucho7_private_write($target,$bytes);
    }
    mucho7_private_write($directory.'/installed.json',json_encode(array('sha256'=>$metadata['sha256'],'files'=>count($contents),'installed_at'=>gmdate('c'))));
    // The pointer changes only after every file has been validated and written.
    mucho7_private_write($root.'/config/php7-core-build.json',json_encode(array('sha256'=>$metadata['sha256'])));
    return count($contents);
}

function mucho7_core_upgrade(PDO $db,$aid,$root,$settings)
{
    $actor=mucho7_admin_user($db,$aid);
    if(!$actor||$actor['role']!=='owner')throw new RuntimeException('Завершить перенос может только владелец сервера.');
    $lock=mucho7_core_lock($root,true);
    try{
        if(!is_file($root.'/config/php7-core-build.json')&&!is_file($root.'/compat/php7/core/sources.json')){
            $count=mucho7_core_install_pack($root);
            return array('message'=>'Сборка проверена: '.$count.' файлов. Перехожу к базе данных.','done'=>false,'delay'=>1000);
        }
        require_once $root.'/compat/php7/domain.php';
        mucho7_domain_configure($settings,$root,$db);
        if(mucho7_domain_complete($db)&&is_file($root.'/config/php7-complete.on')&&!is_file($root.'/config/php7-core-transition.json')){
            return array('message'=>'Полный обработчик ядра уже включён.','done'=>true,'delay'=>0);
        }
        $marker=$root.'/config/php7-core-transition.json';
        if(!is_file($marker)){
            mucho7_private_write($marker,json_encode(array('started_at'=>time())));
            return array('message'=>'Игровой API приостановлен на время переноса. Завершаются текущие запросы.','done'=>false,'delay'=>35000);
        }
        $state=json_decode((string)file_get_contents($marker),true);
        if(!is_array($state)||!isset($state['started_at']))throw new RuntimeException('Повреждено состояние переноса.');
        $wait=35-(time()-(int)$state['started_at']);
        if($wait>0)return array('message'=>'Ожидаю завершения текущих игровых запросов.','done'=>false,'delay'=>$wait*1000);
        if(empty($state['backup_file'])){
            require_once __DIR__.'/admin-backups.php';
            $backup=(new \MuchoCore\Backup\DatabaseBackupService($db,$root.'/config/php7-db-backups'))->create('before-full-core');
            $state['backup_file']=basename($backup['file']);$state['backup_sha256']=hash_file('sha256',$backup['file']);
            mucho7_private_write($marker,json_encode($state));
            return array('message'=>'Резервная копия базы создана и проверена. Продолжаю обновление.','done'=>false,'delay'=>1000);
        }
        $backupPath=$root.'/config/php7-db-backups/'.$state['backup_file'];
        if(basename($state['backup_file'])!==$state['backup_file']||!is_file($backupPath)||!hash_equals($state['backup_sha256'],hash_file('sha256',$backupPath)))throw new RuntimeException('Резервная копия недоступна или повреждена. Перенос остановлен.');
        mucho7_domain_private_directories($root);
        $schema=mucho7_domain_upgrade($db,8);
        if($schema['remaining']>0)return array('message'=>'Обновляю схему. Осталось миграций: '.$schema['remaining'].'.','done'=>false,'delay'=>1000);
        $import=mucho7_domain_import_step($db,$root,100);
        if(!mucho7_domain_complete($db))return array('message'=>'Переношу игровые данные. Этап: '.(isset($import['stage'])?(string)$import['stage']:'следующий').'.','done'=>false,'delay'=>1000);
        mucho7_private_write($root.'/config/php7-complete.on',gmdate('c'));
        if(!unlink($marker))throw new RuntimeException('Не удалось возобновить игровой API.');
        mucho7_admin_audit($db,$aid,'core-upgrade-complete',0);
        return array('message'=>'Полное ядро включено. Данные перенесены, игровой API возобновлён.','done'=>true,'delay'=>0);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

function mucho7_core_render(PDO $db,$root)
{
    $transition=is_file($root.'/config/php7-core-transition.json');
    $active=is_file($root.'/config/php7-complete.on')&&!$transition;
    echo '<section class="card"><h2>Перенос полного ядра</h2><p>'.($active?'Полный обработчик MuchoCore включён на shared.':'Обновление перенесёт игровые данные в схему полного ядра. Текущие аккаунты, ID и доступ к панели сохранятся. На время переноса игровой API будет приостановлен.').'</p>';
    if(admin()['role']==='owner'&&!$active){
        echo '<form method="post" id="coreUpgradeForm"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="core-upgrade"><button>Завершить перенос</button></form>';
        if(!empty($_SESSION['core_upgrade_continue'])){
            $delay=max(1000,min(35000,(int)$_SESSION['core_upgrade_continue']));unset($_SESSION['core_upgrade_continue']);
            echo '<p role="status">Перенос продолжается автоматически. Можно закрыть страницу и продолжить этой кнопкой позже.</p><script>setTimeout(function(){document.getElementById("coreUpgradeForm").requestSubmit ? document.getElementById("coreUpgradeForm").requestSubmit() : document.getElementById("coreUpgradeForm").submit()},'.$delay.');</script>';
        }
    }
    if(admin()['role']==='owner'&&!$transition&&is_file($root.'/config/php7-core-update.json')){
        $available=json_decode((string)file_get_contents($root.'/config/php7-core-update.json'),true);
        $installed=is_file($root.'/config/php7-core-build.json')?json_decode((string)file_get_contents($root.'/config/php7-core-build.json'),true):array();
        if(isset($available['sha256'])&&(!isset($installed['sha256'])||$available['sha256']!==$installed['sha256']))echo '<form method="post"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="core-install"><button>Установить загруженную сборку</button></form>';
    }
    echo '</section>';
}

function mucho7_core_deploy(PDO $db,$aid,$root)
{
    $actor=mucho7_admin_user($db,$aid);if(!$actor||$actor['role']!=='owner')throw new RuntimeException('Установить сборку может только владелец.');
    $lock=mucho7_core_lock($root,true);
    try{
        if(is_file($root.'/config/php7-core-transition.json'))throw new RuntimeException('Сначала заверши текущий перенос.');
        $count=mucho7_core_install_pack($root);
        mucho7_private_write($root.'/config/php7-core-transition.json',json_encode(array('started_at'=>time())));
        mucho7_admin_audit($db,$aid,'core-build-install',0,(string)$count);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    return array('message'=>'Сборка установлена. Продолжаю обновление схемы.','done'=>false,'delay'=>35000);
}
