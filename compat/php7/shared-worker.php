<?php
/** Bounded shared queue processing, including explicitly configured webhooks. */
declare(strict_types=1);
require_once __DIR__.'/domain.php';
require_once __DIR__.'/webhooks.php';
function mucho7_shared_worker(PDO $db,$root,$settings,$limit=4,$deliver=null)
{
    if(!is_file($root.'/config/php7-complete.on')||is_file($root.'/config/php7-core-transition.json'))throw new RuntimeException('Complete the core migration first.');
    mucho7_domain_configure($settings,$root,$db);
    if((new ReflectionMethod('MuchoCore\\Job\\JobQueue','reserve'))->getNumberOfParameters()<2)throw new RuntimeException('Install the uploaded build on the Server page to run the queue.');
    $lock=fopen($root.'/config/php7-worker.lock','c');
    if(!$lock)throw new RuntimeException('Unable to open the queue lock.');
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return array('done'=>0,'failed'=>0,'busy'=>true);}
    $result=array('done'=>0,'failed'=>0,'busy'=>false);$deadline=microtime(true)+4;
    try{
        // Disabled external delivery never consumes or recovers webhook jobs.
        $leaseTypes="'level.index','maintenance.cleanup'".(!empty($settings['enable_webhooks'])?",'webhook.dispatch'":'');
        $db->exec("UPDATE mucho_jobs SET status=IF(attempts>=5,'failed','queued'),last_error='Worker lease expired.',worker_id=NULL,locked_at=NULL,available_at=UTC_TIMESTAMP(),updated_at=CURRENT_TIMESTAMP WHERE status='running' AND job_type IN (".$leaseTypes.") AND locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) LIMIT 128");
        $queue=new \MuchoCore\Job\JobQueue($db);$indexer=new \MuchoCore\Search\LevelSearchIndexer($db);
        $types=array('level.index','maintenance.cleanup');
        if(!empty($settings['enable_webhooks']))$types[]='webhook.dispatch';
        $worker='shared:'.bin2hex(random_bytes(12));
        for($i=0;$i<max(1,min(8,(int)$limit))&&microtime(true)<$deadline;$i++){
            $job=$queue->reserve($worker,$types);if(!$job)break;
            try{
                if($job['job_type']==='level.index'){
                    $id=isset($job['payload']['level_id'])?(int)$job['payload']['level_id']:0;
                    if($id<=0||!$indexer->upsert($id))throw new RuntimeException('Level index job failed.');
                }elseif($job['job_type']==='webhook.dispatch'){
                    $payload=$job['payload'];$event=isset($payload['event'])?$payload['event']:'';$data=isset($payload['data'])?$payload['data']:array();$requestId=isset($payload['request_id'])?$payload['request_id']:'';
                    $ok=$deliver!==null?$deliver($event,$data,$requestId):mucho7_shared_webhook_dispatch($settings,$event,$data,$requestId);
                    if(!$ok)throw new RuntimeException('Webhook delivery failed.');
                }else{
                    $db->exec("DELETE FROM mucho_jobs WHERE status IN ('done','failed') AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) LIMIT 500");
                    $db->exec('DELETE FROM mucho_cache WHERE expires_at>0 AND expires_at<=UNIX_TIMESTAMP() LIMIT 500');
                    \MuchoCore\Level\LevelDownloadTracker::pruneExpired($db,128);
                }
                if($queue->complete((int)$job['id'],$worker,(int)$job['attempts']))$result['done']++;
            }catch(Throwable $e){
                if($queue->fail((int)$job['id'],'Shared local job failed.',$worker,(int)$job['attempts']))$result['failed']++;
                error_log('[MuchoCore Shared worker] '.get_class($e));
            }
        }
        return $result;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
