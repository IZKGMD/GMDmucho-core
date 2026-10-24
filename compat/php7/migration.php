<?php
/** Original schema detector/import adapter, bound to an already upgraded host. */
declare(strict_types=1);
require_once __DIR__.'/domain.php';
function mucho7_shared_migrate(PDO $target,PDO $source,$root,$apply=false)
{
    if(!is_file($root.'/config/php7-complete.on')||is_file($root.'/config/php7-core-transition.json'))throw new RuntimeException('Complete the core migration first.');
    $detected=(new \MuchoCore\Migration\MigrationAdapterRegistry($target))->detect($source);
    $adapter=$detected['adapter'];$result=array('inspection'=>$detected['inspection'],'preflight'=>$adapter->preflight($source));
    if(!$apply)return $result;
    $lock=mucho7_core_lock($root,true);
    try{
        foreach(array('mucho_cvolton_account_map','mucho_cvolton_level_map') as $table)if(!tableExists($target,$table))throw new RuntimeException('Migration mapping tables are unavailable. Install the full core build first.');
        $result['backup']=(new \MuchoCore\Backup\DatabaseBackupService($target,$root.'/config/php7-db-backups'))->create('before-source-import');
        $target->beginTransaction();
        try{$result['stats']=$adapter->apply($source);$target->commit();}
        catch(Throwable $e){if($target->inTransaction())$target->rollBack();throw $e;}
        return $result;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
