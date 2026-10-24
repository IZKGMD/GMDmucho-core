<?php
/** Resumable search rebuild for hosts without the operator CLI. */
declare(strict_types=1);
require_once __DIR__.'/domain.php';
function mucho7_shared_index(PDO $db,$root,$settings,$restart=false)
{
    if(!is_file($root.'/config/php7-complete.on')||is_file($root.'/config/php7-core-transition.json'))throw new RuntimeException('Complete the core migration first.');
    $lock=fopen($root.'/config/php7-index.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);throw new RuntimeException('Search indexing is already running.');}
    try{
        $path=$root.'/config/php7-index.json';
        if(is_link($path))throw new RuntimeException('Invalid search index checkpoint.');
        $state=$restart?array('after'=>0,'processed'=>0,'done'=>false):(is_file($path)?json_decode((string)file_get_contents($path),true):null);
        if($state===null)return array('done'=>true,'processed'=>0,'after'=>0);
        if(!is_array($state)||!isset($state['after'],$state['processed'],$state['done']))throw new RuntimeException('Invalid search index checkpoint.');
        if($state['done'])return $state;
        mucho7_domain_configure($settings,$root,$db);$indexer=new \MuchoCore\Search\LevelSearchIndexer($db);
        $query=$db->prepare('SELECT level_id FROM levels WHERE level_id>? ORDER BY level_id LIMIT 32');$query->execute(array((int)$state['after']));$ids=$query->fetchAll(PDO::FETCH_COLUMN);
        $deadline=microtime(true)+4;$count=0;
        foreach($ids as $id){if(microtime(true)>=$deadline)break;if(!$indexer->upsert((int)$id))throw new RuntimeException('Search index rebuild failed. Retry the current batch.');$state['after']=(int)$id;$state['processed']++;$count++;
            mucho7_private_write($path,json_encode($state));}
        $state['done']=count($ids)<32&&$count===count($ids);mucho7_private_write($path,json_encode($state));return $state;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
