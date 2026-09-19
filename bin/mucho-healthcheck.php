<?php
declare(strict_types=1);

/*
 * MuchoCore Healthcheck
 * Copyright (C) 2026 IZK
 */

require_once '/var/www/mucho-core/public/api/v2/bootstrap.php';

function alertOnce(
    PDO $db,
    string $severity,
    string $source,
    string $message
): void {

    $q=$db->prepare("
        SELECT id
        FROM mucho_system_alerts
        WHERE resolved=0
          AND source=?
          AND message=?
        LIMIT 1
    ");

    $q->execute([
        $source,
        $message
    ]);

    if($q->fetchColumn()!==false){
        return;
    }

    $q=$db->prepare("
        INSERT INTO mucho_system_alerts
        (severity,source,message)
        VALUES (?,?,?)
    ");

    $q->execute([
        $severity,
        $source,
        $message
    ]);
}


try {

    $db=muchoV2Db();

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
            alertOnce(
                $db,
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
            alertOnce(
                $db,
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
