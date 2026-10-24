<?php
/** Shared-host cron work with private checkpoints and no external worker. */
declare(strict_types=1);
require_once __DIR__.'/shared-worker.php';
require_once __DIR__.'/shared-index.php';
function mucho7_shared_schedule(PDO $db,$root,$settings,$backup=false)
{
    if(!is_file($root.'/config/php7-complete.on')||is_file($root.'/config/php7-core-transition.json'))throw new RuntimeException('Complete the core migration first.');
    $lock=fopen($root.'/config/php7-scheduler.lock','c');
    if(!$lock)throw new RuntimeException('Unable to open the scheduler lock.');
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return array('busy'=>true);}
    try{
        $path=$root.'/config/php7-scheduler.json';
        if(is_link($path)||is_file($path)&&filesize($path)>65536)throw new RuntimeException('Invalid scheduler checkpoint.');
        $state=is_file($path)?json_decode((string)file_get_contents($path),true):array();
        if(!is_array($state))throw new RuntimeException('Invalid scheduler checkpoint.');
        $now=time();
        if(!isset($state['maintenance_at'])||$now-(int)$state['maintenance_at']>=3600){
            (new \MuchoCore\Job\JobQueue($db))->enqueue('maintenance.cleanup');
            $state['maintenance_at']=$now;mucho7_private_write($path,json_encode($state));
        }
        $result=mucho7_shared_worker($db,$root,$settings);$result['index']=mucho7_shared_index($db,$root,$settings);$result['backup']='not_requested';
        if($backup){
            $result['backup']='not_due';
            if(!isset($state['backup_at'])||$now-(int)$state['backup_at']>=86400){
                if(!class_exists('MuchoCore\\Backup\\DatabaseBackupService',false))require_once __DIR__.'/admin-ui/DatabaseBackupService.php';
                $archive=(new \MuchoCore\Backup\DatabaseBackupService($db,$root.'/config/php7-db-backups'))->create('scheduled');
                $state['backup_at']=time();$state['backup_file']=basename($archive['file']);
                mucho7_private_write($path,json_encode($state));$result['backup']='verified';
            }
        }
        return $result;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
