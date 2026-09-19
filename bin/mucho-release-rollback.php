<?php
declare(strict_types=1);

/*
 * MuchoCore Release Rollback
 * Copyright (C) 2026 IZK
 */

require_once '/var/www/mucho-core/public/api/v2/bootstrap.php';

$db=muchoV2Db();

$cmd=$argv[1] ?? '';

if($cmd==='--list'){

    $rows=$db->query("
        SELECT
            id,
            version,
            size_bytes,
            sha256,
            signer_sha256,
            download_url,
            created_at
        FROM mucho_client_release_files
        WHERE platform='android'
        ORDER BY id DESC
        LIMIT 20
    ")->fetchAll();

    echo json_encode(
        $rows,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES
    ).PHP_EOL;

    exit;
}


if($cmd==='--rollback'){

    $id=(int)($argv[2] ?? 0);

    if($id<=0){
        throw new RuntimeException(
            'usage: --rollback RELEASE_ID'
        );
    }

    $q=$db->prepare("
        SELECT *
        FROM mucho_client_release_files
        WHERE id=?
          AND platform='android'
        LIMIT 1
    ");

    $q->execute([$id]);

    $r=$q->fetch();

    if(!$r){
        throw new RuntimeException(
            'Release not found.'
        );
    }

    $q=$db->prepare("
        UPDATE mucho_client_releases
        SET
            current_version=?,
            download_url=?,
            sha256=?,
            signer_sha256=?
        WHERE platform='android'
    ");

    $q->execute([
        $r['version'],
        $r['download_url'],
        $r['sha256'],
        $r['signer_sha256']
    ]);

    echo
        "ROLLBACK_OK\n".
        "VERSION=".$r['version']."\n".
        "URL=".$r['download_url']."\n";

    exit;
}

fwrite(
    STDERR,
    "Commands:\n".
    "  --list\n".
    "  --rollback RELEASE_ID\n"
);

exit(1);
