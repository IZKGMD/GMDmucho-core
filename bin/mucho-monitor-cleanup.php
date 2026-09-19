<?php
declare(strict_types=1);

/*
 * MuchoCore Monitoring Cleanup
 * Copyright (C) 2026 IZK
 */

require_once
    '/var/www/mucho-core/public/api/v2/bootstrap.php';

$db=muchoV2Db();

$tables=[
    'mucho_api_metrics_minute',
    'mucho_security_events',
    'mucho_system_alerts'
];

foreach($tables as $table){

    $q=$db->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema=DATABASE()
          AND table_name=?
    ");

    $q->execute([$table]);

    if(!(int)$q->fetchColumn()){
        continue;
    }

    if(
        $table===
        'mucho_api_metrics_minute'
    ){
        $db->exec("
            DELETE FROM mucho_api_metrics_minute
            WHERE minute_start <
                DATE_SUB(
                    NOW(),
                    INTERVAL 30 DAY
                )
        ");
    }

    elseif(
        $table===
        'mucho_security_events'
    ){
        $db->exec("
            DELETE FROM mucho_security_events
            WHERE created_at <
                DATE_SUB(
                    NOW(),
                    INTERVAL 30 DAY
                )
        ");
    }

    elseif(
        $table===
        'mucho_system_alerts'
    ){
        $db->exec("
            DELETE FROM mucho_system_alerts
            WHERE resolved=1
              AND resolved_at <
                DATE_SUB(
                    NOW(),
                    INTERVAL 30 DAY
                )
        ");
    }
}

if(
    $db->query("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema=DATABASE()
          AND table_name='mucho_api_rate_limits'
    ")->fetchColumn()
){
    $db->exec("
        DELETE FROM mucho_api_rate_limits
        WHERE updated_at <
            DATE_SUB(
                NOW(),
                INTERVAL 1 DAY
            )
    ");
}

echo "MONITOR_CLEANUP_OK\n";
