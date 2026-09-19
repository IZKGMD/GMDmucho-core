<?php
declare(strict_types=1);

/*
 * MuchoCore Client Config API v2.3
 * Copyright (C) 2026 IZK
 */

require_once __DIR__.'/bootstrap.php';

muchoV2RequireMethod('GET');

try {

    $platform=strtolower(
        trim((string)($_GET['platform'] ?? ''))
    );

    $clientVersion=trim(
        (string)($_GET['version'] ?? '')
    );

    if(
        !in_array(
            $platform,
            ['android','windows'],
            true
        )
    ){
        muchoV2Fail(
            'invalid_platform',
            400
        );
    }

    $db=muchoV2Db();

    $flags=[];

    $rows=$db->query("
        SELECT flag_key,enabled
        FROM mucho_feature_flags
        ORDER BY flag_key
    ")->fetchAll();

    foreach($rows as $row){
        $flags[(string)$row['flag_key']]
            =(bool)$row['enabled'];
    }

    $q=$db->prepare("
        SELECT
            current_version,
            minimum_version,
            download_url,
            sha256,
            release_notes,
            maintenance,
            updated_at
        FROM mucho_client_releases
        WHERE platform=?
        LIMIT 1
    ");

    $q->execute([$platform]);
    $release=$q->fetch();

    $current=$release['current_version'] ?? null;
    $minimum=$release['minimum_version'] ?? null;

    $updateAvailable=false;
    $updateRequired=false;

    if(
        $clientVersion!=='' &&
        is_string($current) &&
        $current!==''
    ){
        $updateAvailable=
            version_compare(
                $clientVersion,
                $current,
                '<'
            );
    }

    if(
        $clientVersion!=='' &&
        is_string($minimum) &&
        $minimum!==''
    ){
        $updateRequired=
            version_compare(
                $clientVersion,
                $minimum,
                '<'
            );
    }

    muchoV2Send([
        'ok'=>true,

        'api'=>'MuchoCore',
        'version'=>'2.3',

        'platform'=>$platform,

        'client'=>[
            'reported_version'=>
                $clientVersion!=='' ? $clientVersion : null,

            'current_version'=>$current,

            'minimum_version'=>$minimum,

            'update_available'=>$updateAvailable,

            'update_required'=>$updateRequired,

            'maintenance'=>
                (bool)($release['maintenance'] ?? false),

            'download_url'=>
                $release['download_url'] ?? null,

            'sha256'=>
                $release['sha256'] ?? null,

            'release_notes'=>
                $release['release_notes'] ?? null
        ],

        'features'=>$flags,

        'config_updated_at'=>
            $release['updated_at'] ?? null
    ]);

}catch(Throwable $e){

    error_log(
        '[MuchoCore Client Config] '.
        $e->getMessage()
    );

    muchoV2Fail(
        'internal_error',
        500
    );
}
