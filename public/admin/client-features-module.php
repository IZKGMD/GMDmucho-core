<?php
declare(strict_types=1);

/*
 * MuchoCore Client & Features Admin
 * Copyright (C) 2026 IZK
 */

function ensureClientFeatureTables(PDO $db): void
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

    $defaults=[
        ['mucho_profiles',1,'Mucho Profiles'],
        ['custom_music',1,'Custom music system'],
        ['online_presence',1,'Player online presence'],
        ['android_host',1,'Mucho Android native host'],
        ['creator_tools',0,'Mucho creator tools'],
        ['experimental_features',0,'Experimental features']
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

ensureClientFeatureTables($db);


/* =========================================================
   ACTIONS
========================================================= */

if ($_SERVER['REQUEST_METHOD']==='POST') {

    $cfAction=(string)($_POST['action'] ?? '');

    if (in_array(
        $cfAction,
        [
            'clientflag-save',
            'clientrelease-save'
        ],
        true
    )) {

        try {

            checkCsrf();
            requireRank(30);

            if ($cfAction==='clientflag-save') {

                $key=trim(
                    (string)($_POST['flag_key'] ?? '')
                );

                $enabled=(int)(
                    $_POST['enabled'] ?? 0
                );

                if (
                    !preg_match(
                        '/^[a-z0-9_]{2,64}$/',
                        $key
                    ) ||
                    !in_array($enabled,[0,1],true)
                ) {
                    throw new RuntimeException(
                        'Invalid feature flag.'
                    );
                }

                $q=$db->prepare("
                    UPDATE mucho_feature_flags
                    SET enabled=:enabled
                    WHERE flag_key=:flag
                ");

                $q->execute([
                    'enabled'=>$enabled,
                    'flag'=>$key
                ]);

                audit(
                    $db,
                    'client.feature_flag',
                    $key,
                    ['enabled'=>$enabled]
                );

                flash(
                    'Feature flag updated.'
                );
            }


            elseif ($cfAction==='clientrelease-save') {

                $platform=strtolower(
                    trim(
                        (string)(
                            $_POST['platform']
                            ?? ''
                        )
                    )
                );

                if (
                    !in_array(
                        $platform,
                        ['android','windows'],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Invalid platform.'
                    );
                }

                $current=trim(
                    (string)(
                        $_POST['current_version']
                        ?? ''
                    )
                );

                $minimum=trim(
                    (string)(
                        $_POST['minimum_version']
                        ?? ''
                    )
                );

                foreach(
                    [$current,$minimum]
                    as $version
                ) {
                    if (
                        $version!=='' &&
                        !preg_match(
                            '/^[0-9A-Za-z._+\-]{1,32}$/',
                            $version
                        )
                    ) {
                        throw new RuntimeException(
                            'Invalid version.'
                        );
                    }
                }

                $url=trim(
                    (string)(
                        $_POST['download_url']
                        ?? ''
                    )
                );

                if (
                    $url!=='' &&
                    !filter_var(
                        $url,
                        FILTER_VALIDATE_URL
                    )
                ) {
                    throw new RuntimeException(
                        'Invalid download URL.'
                    );
                }

                $sha=strtolower(
                    trim(
                        (string)(
                            $_POST['sha256']
                            ?? ''
                        )
                    )
                );

                if (
                    $sha!=='' &&
                    !preg_match(
                        '/^[a-f0-9]{64}$/',
                        $sha
                    )
                ) {
                    throw new RuntimeException(
                        'Invalid SHA-256.'
                    );
                }

                $notes=trim(
                    (string)(
                        $_POST['release_notes']
                        ?? ''
                    )
                );

                if (
                    mb_strlen(
                        $notes,
                        'UTF-8'
                    )>10000
                ) {
                    throw new RuntimeException(
                        'Release notes are too long.'
                    );
                }

                $maintenance=
                    isset($_POST['maintenance'])
                    ? 1
                    : 0;

                $q=$db->prepare("
                    INSERT INTO mucho_client_releases
                    (
                        platform,
                        current_version,
                        minimum_version,
                        download_url,
                        sha256,
                        release_notes,
                        maintenance
                    )
                    VALUES
                    (
                        :platform,
                        :current,
                        :minimum,
                        :url,
                        :sha,
                        :notes,
                        :maintenance
                    )
                    ON DUPLICATE KEY UPDATE
                        current_version=
                            VALUES(current_version),
                        minimum_version=
                            VALUES(minimum_version),
                        download_url=
                            VALUES(download_url),
                        sha256=
                            VALUES(sha256),
                        release_notes=
                            VALUES(release_notes),
                        maintenance=
                            VALUES(maintenance)
                ");

                $q->execute([
                    'platform'=>$platform,
                    'current'=>$current ?: null,
                    'minimum'=>$minimum ?: null,
                    'url'=>$url ?: null,
                    'sha'=>$sha ?: null,
                    'notes'=>$notes ?: null,
                    'maintenance'=>$maintenance
                ]);

                audit(
                    $db,
                    'client.release_save',
                    $platform,
                    [
                        'current'=>$current,
                        'minimum'=>$minimum,
                        'maintenance'=>$maintenance
                    ]
                );

                flash(
                    ucfirst($platform).
                    ' client configuration saved.'
                );
            }

        } catch(Throwable $e) {

            flash(
                'Error: '.$e->getMessage(),
                'error'
            );
        }

        header(
            'Location:/admin/?page=clientfeatures'
        );

        exit;
    }
}


/* =========================================================
   PAGE
========================================================= */

function renderClientFeaturesPage(PDO $db): void
{
    $flags=$db->query("
        SELECT
            flag_key,
            enabled,
            description,
            updated_at
        FROM mucho_feature_flags
        ORDER BY flag_key
    ")->fetchAll();

    $releases=[];

    foreach(
        $db->query("
            SELECT *
            FROM mucho_client_releases
        ")->fetchAll()
        as $row
    ){
        $releases[$row['platform']]=$row;
    }

    $canEdit=
        admin() &&
        rank((string)admin()['role'])>=30;

    ?>
    
    <div class="boxgrid">

        <div class="card">
            <small>CLIENT CONFIG API</small>

            <div class="value">
                MuchoCore v2.3
            </div>

            <p class="muted">
                Remote configuration for Android
                and Windows Mucho clients.
            </p>

            <div class="row">
                <a
                    class="btn gray"
                    target="_blank"
                    href="/api/v2/client-config?platform=android&version=1.0.0"
                >
                    Test Android API
                </a>

                <a
                    class="btn gray"
                    target="_blank"
                    href="/api/v2/client-config?platform=windows&version=1.0.0"
                >
                    Test Windows API
                </a>
            </div>
        </div>

        <div class="card">
            <small>HOW IT WORKS</small>

            <h2 style="margin:6px 0 9px">
                Remote client control
            </h2>

            <p class="muted">
                Feature flags can be changed instantly.
                Mucho clients receive the new configuration
                without rebuilding the APK or DLL.
            </p>
        </div>

    </div>


    <h2>Feature Flags</h2>

    <div class="table">
        <table>
            <thead>
                <tr>
                    <th>Feature</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <th>Control</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach($flags as $flag): ?>

                <tr>

                    <td>
                        <b><?=h($flag['flag_key'])?></b>
                    </td>

                    <td class="muted">
                        <?=h($flag['description'])?>
                    </td>

                    <td>
                        <?php if((int)$flag['enabled']===1): ?>
                            <span class="badge green">
                                ENABLED
                            </span>
                        <?php else: ?>
                            <span class="badge">
                                DISABLED
                            </span>
                        <?php endif ?>
                    </td>

                    <td class="muted">
                        <?=h($flag['updated_at'])?>
                    </td>

                    <td>

                    <?php if($canEdit): ?>

                        <form
                            method="post"
                            style="margin:0"
                        >
                            <input
                                type="hidden"
                                name="csrf"
                                value="<?=h(csrf())?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="clientflag-save"
                            >

                            <input
                                type="hidden"
                                name="flag_key"
                                value="<?=h($flag['flag_key'])?>"
                            >

                            <input
                                type="hidden"
                                name="enabled"
                                value="<?=
                                    (int)$flag['enabled']
                                    ? '0'
                                    : '1'
                                ?>"
                            >

                            <button
                                type="submit"
                                class="<?=
                                    (int)$flag['enabled']
                                    ? 'red'
                                    : 'green'
                                ?>"
                            >
                                <?=
                                    (int)$flag['enabled']
                                    ? 'Disable'
                                    : 'Enable'
                                ?>
                            </button>
                        </form>

                    <?php else: ?>

                        <span class="muted">
                            Read only
                        </span>

                    <?php endif ?>

                    </td>

                </tr>

            <?php endforeach ?>

            </tbody>
        </table>
    </div>


    <h2>Client Releases</h2>

    <div class="boxgrid">

    <?php foreach(['android','windows'] as $platform):

        $r=$releases[$platform] ?? [];

    ?>

        <div class="card">

            <div class="row"
                 style="justify-content:space-between">

                <div>
                    <small>PLATFORM</small>
                    <h2 style="margin:4px 0">
                        <?=h(ucfirst($platform))?>
                    </h2>
                </div>

                <?php if(!empty($r['maintenance'])): ?>

                    <span class="badge red">
                        MAINTENANCE
                    </span>

                <?php else: ?>

                    <span class="badge green">
                        ACTIVE
                    </span>

                <?php endif ?>

            </div>

            <form method="post">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?=h(csrf())?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="clientrelease-save"
                >

                <input
                    type="hidden"
                    name="platform"
                    value="<?=h($platform)?>"
                >


                <p>
                    <small>Current version</small><br>

                    <input
                        name="current_version"
                        value="<?=h(
                            $r['current_version']
                            ?? ''
                        )?>"
                        placeholder="1.0.0"
                        style="width:100%"
                        <?=$canEdit?'':'disabled'?>
                    >
                </p>


                <p>
                    <small>Minimum supported version</small><br>

                    <input
                        name="minimum_version"
                        value="<?=h(
                            $r['minimum_version']
                            ?? ''
                        )?>"
                        placeholder="1.0.0"
                        style="width:100%"
                        <?=$canEdit?'':'disabled'?>
                    >
                </p>


                <p>
                    <small>Download URL</small><br>

                    <input
                        name="download_url"
                        value="<?=h(
                            $r['download_url']
                            ?? ''
                        )?>"
                        placeholder="https://..."
                        style="width:100%"
                        <?=$canEdit?'':'disabled'?>
                    >
                </p>


                <p>
                    <small>SHA-256</small><br>

                    <input
                        name="sha256"
                        value="<?=h(
                            $r['sha256']
                            ?? ''
                        )?>"
                        placeholder="64-character SHA-256"
                        style="width:100%"
                        <?=$canEdit?'':'disabled'?>
                    >
                </p>


                <p>
                    <small>Release notes</small>

                    <textarea
                        name="release_notes"
                        placeholder="What's new..."
                        <?=$canEdit?'':'disabled'?>
                    ><?=h(
                        $r['release_notes']
                        ?? ''
                    )?></textarea>
                </p>


                <label
                    class="row"
                    style="margin:13px 0"
                >
                    <input
                        type="checkbox"
                        name="maintenance"
                        value="1"
                        <?=!empty($r['maintenance'])
                            ? 'checked'
                            : ''
                        ?>
                        <?=$canEdit?'':'disabled'?>
                    >

                    Maintenance mode
                </label>


                <?php if($canEdit): ?>

                    <button
                        type="submit"
                        class="btn"
                    >
                        Save <?=h(ucfirst($platform))?>
                    </button>

                <?php endif ?>

            </form>


            <?php if(!empty($r['updated_at'])): ?>

                <p class="muted">
                    Last update:
                    <?=h($r['updated_at'])?>
                </p>

            <?php endif ?>

        </div>

    <?php endforeach ?>

    </div>

    <?php
}
