<?php
declare(strict_types=1);

/*
 * MuchoCore Client Config v2.3
 * Copyright (C) 2026 IZK
 */

require_once '/var/www/mucho-core/public/api/v2/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function installConfig(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_feature_flags (
            flag_key VARCHAR(64) PRIMARY KEY,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            description VARCHAR(255) NOT NULL DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_client_releases (
            platform VARCHAR(32) PRIMARY KEY,
            current_version VARCHAR(32) NULL,
            minimum_version VARCHAR(32) NULL,
            download_url VARCHAR(500) NULL,
            sha256 VARCHAR(64) NULL,
            release_notes TEXT NULL,
            maintenance TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $defaults = [
        ['mucho_profiles',1,'Mucho Profiles'],
        ['custom_music',1,'Custom music system'],
        ['online_presence',1,'Player online presence'],
        ['android_host',1,'Mucho Android native host'],
        ['creator_tools',0,'Mucho creator tools'],
        ['experimental_features',0,'Experimental client features']
    ];

    $q=$db->prepare("
        INSERT IGNORE INTO mucho_feature_flags
        (flag_key,enabled,description)
        VALUES (?,?,?)
    ");

    foreach($defaults as $row){
        $q->execute($row);
    }
}

try {

    $db=muchoV2Db();
    installConfig($db);

    $cmd=$argv[1] ?? '';

    if($cmd==='--install'){
        echo "CLIENT_CONFIG_INSTALL_OK\n";
        exit;
    }

    if($cmd==='--flag'){

        $key=trim((string)($argv[2] ?? ''));
        $enabled=(string)($argv[3] ?? '');

        if(
            !preg_match('/^[a-z0-9_]{2,64}$/',$key) ||
            !in_array($enabled,['0','1'],true)
        ){
            throw new RuntimeException(
                'usage: --flag FLAG_KEY 0|1'
            );
        }

        $q=$db->prepare("
            INSERT INTO mucho_feature_flags
            (flag_key,enabled)
            VALUES (?,?)
            ON DUPLICATE KEY UPDATE
                enabled=VALUES(enabled)
        ");

        $q->execute([$key,(int)$enabled]);

        echo "FLAG_UPDATED: {$key}={$enabled}\n";
        exit;
    }

    if($cmd==='--release'){

        $platform=strtolower(
            trim((string)($argv[2] ?? ''))
        );

        $current=trim((string)($argv[3] ?? ''));
        $minimum=trim((string)($argv[4] ?? ''));

        if(
            !in_array($platform,['android','windows'],true) ||
            $current==='' ||
            $minimum===''
        ){
            throw new RuntimeException(
                'usage: --release android|windows CURRENT_VERSION MINIMUM_VERSION'
            );
        }

        $q=$db->prepare("
            INSERT INTO mucho_client_releases
            (
                platform,
                current_version,
                minimum_version
            )
            VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE
                current_version=VALUES(current_version),
                minimum_version=VALUES(minimum_version)
        ");

        $q->execute([
            $platform,
            $current,
            $minimum
        ]);

        echo "RELEASE_UPDATED\n";
        exit;
    }

    if($cmd==='--download'){

        $platform=strtolower(
            trim((string)($argv[2] ?? ''))
        );

        $url=trim((string)($argv[3] ?? ''));

        if(
            !in_array($platform,['android','windows'],true) ||
            $url===''
        ){
            throw new RuntimeException(
                'usage: --download android|windows URL'
            );
        }

        $q=$db->prepare("
            UPDATE mucho_client_releases
            SET download_url=?
            WHERE platform=?
        ");

        $q->execute([$url,$platform]);

        echo "DOWNLOAD_URL_UPDATED\n";
        exit;
    }

    if($cmd==='--sha256'){

        $platform=strtolower(
            trim((string)($argv[2] ?? ''))
        );

        $hash=strtolower(
            trim((string)($argv[3] ?? ''))
        );

        if(
            !in_array($platform,['android','windows'],true) ||
            !preg_match('/^[a-f0-9]{64}$/',$hash)
        ){
            throw new RuntimeException(
                'usage: --sha256 android|windows HASH'
            );
        }

        $q=$db->prepare("
            UPDATE mucho_client_releases
            SET sha256=?
            WHERE platform=?
        ");

        $q->execute([$hash,$platform]);

        echo "SHA256_UPDATED\n";
        exit;
    }

    if($cmd==='--maintenance'){

        $platform=strtolower(
            trim((string)($argv[2] ?? ''))
        );

        $enabled=(string)($argv[3] ?? '');

        if(
            !in_array($platform,['android','windows'],true) ||
            !in_array($enabled,['0','1'],true)
        ){
            throw new RuntimeException(
                'usage: --maintenance android|windows 0|1'
            );
        }

        $q=$db->prepare("
            UPDATE mucho_client_releases
            SET maintenance=?
            WHERE platform=?
        ");

        $q->execute([
            (int)$enabled,
            $platform
        ]);

        echo "MAINTENANCE_UPDATED\n";
        exit;
    }

    if($cmd==='--show'){

        echo "=== FEATURES ===\n";

        foreach(
            $db->query("
                SELECT flag_key,enabled,description,updated_at
                FROM mucho_feature_flags
                ORDER BY flag_key
            ")->fetchAll() as $row
        ){
            echo sprintf(
                "%-24s %s  %s\n",
                $row['flag_key'],
                $row['enabled'] ? 'ON ' : 'OFF',
                $row['description']
            );
        }

        echo "\n=== RELEASES ===\n";

        echo json_encode(
            $db->query("
                SELECT *
                FROM mucho_client_releases
                ORDER BY platform
            ")->fetchAll(),
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES
        ).PHP_EOL;

        exit;
    }

    throw new RuntimeException(
        'commands: --install | --flag | --release | --download | --sha256 | --maintenance | --show'
    );

}catch(Throwable $e){

    fwrite(
        STDERR,
        'ERROR: '.$e->getMessage().PHP_EOL
    );

    exit(1);
}
