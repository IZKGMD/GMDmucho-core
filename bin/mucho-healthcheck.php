<?php
declare(strict_types=1);

/*
 * MuchoCore Healthcheck
 * Copyright (C) 2026 IZK
 */

require_once '/var/www/mucho-core/public/api/v2/bootstrap.php';

use MuchoCore\Job\JobQueue;
use MuchoCore\Monitoring\AlertService;

try {

    $db=muchoV2Db();
    $alerts=new AlertService($db,new JobQueue($db));
    $alerts->ensureStorage();

    $db->query('SELECT 1')->fetchColumn();

    foreach([
        'accounts',
        'levels',
        'songs',
        'mucho_profile_customization'
    ] as $table){

        $q=$db->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema=DATABASE()
              AND table_name=?
        ");

        $q->execute([$table]);

        if(!(int)$q->fetchColumn()){
            $alerts->raise(
                'critical',
                'database',
                "Missing table: $table"
            );
        }
    }

    echo "HEALTH_OK\n";
    exit(0);

} catch(Throwable $e){

    try {
        if(isset($db)){
            $alerts=new AlertService($db,new JobQueue($db));
            $alerts->raise(
                'critical',
                'healthcheck',
                $e->getMessage()
            );
        }
    } catch(Throwable) {
    }

    fwrite(
        STDERR,
        'HEALTH_FAIL: '.
        $e->getMessage().
        PHP_EOL
    );

    exit(1);
}
