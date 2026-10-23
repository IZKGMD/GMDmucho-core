<?php
function renderDbBackupCenter(
    PDO $db
) {

    $canManage=canPermission('backups.download');

    $isOwner=admin() && admin()['role']==='owner';

    $sharedHosting=muchodbSharedHosting();


    $files=[];

    foreach(
        glob(
            muchoDbBackupDir().
            '/*.sql.gz'
        ) ?: []
        as $path
    ){
        if(!is_file($path)){
            continue;
        }

        $name=basename($path);

        if(
            !muchoDbBackupValidName(
                $name
            )
        ){
            continue;
        }

        $size=(int)filesize($path);
        $mtime=(int)filemtime($path);

        $shaActual=
            hash_file(
                'sha256',
                $path
            );

        $shaExpected=null;

        if(
            is_file(
                $path.'.sha256'
            )
        ){
            $raw=trim(
                (string)file_get_contents(
                    $path.'.sha256'
                )
            );

            $parts=
                preg_split(
                    '/\s+/',
                    $raw
                );

            if(
                isset($parts[0]) &&
                preg_match(
                    '/^[a-f0-9]{64}$/i',
                    $parts[0]
                )
            ){
                $shaExpected=
                    strtolower(
                        $parts[0]
                    );
            }
        }

        $valid=
            is_string($shaActual) &&
            is_string($shaExpected) &&
            hash_equals(
                strtolower($shaActual),
                $shaExpected
            );

        $files[]=[
            'name'=>$name,
            'path'=>$path,
            'size'=>$size,
            'mtime'=>$mtime,
            'sha'=>$shaActual,
            'valid'=>$valid
        ];
    }

    usort(
        $files,
        static function($a,$b){return $b['mtime'] <=> $a['mtime'];}
    );

    $totalSize=0;

    foreach($files as $f){
        $totalSize += $f['size'];
    }

    $latest=
        $files[0] ?? null;

    ?>

<style>

.db-backup-grid{
    display:grid;
    grid-template-columns:
        repeat(
            auto-fit,
            minmax(190px,1fr)
        );
    gap:14px;
    margin-bottom:22px;
}

.db-backup-stat{
    padding:18px;
}

.db-backup-value{
    font-size:26px;
    font-weight:800;
    margin-top:7px;
}

.db-backup-actions{
    display:flex;
    gap:7px;
    flex-wrap:wrap;
}

.db-sha{
    font-family:
        ui-monospace,
        monospace;
    font-size:12px;
}

.db-restore-box{
    margin-top:10px;
    padding:12px;
    border:1px solid rgba(255,90,90,.25);
    border-radius:10px;
}

</style>


<div
    class="row"
    style="
        justify-content:space-between;
        align-items:flex-start
    "
>

    <div>

        <h1>
            DB Backup Center
        </h1>

        <div class="muted">
            Manual backups ·
            SHA-256 verification
        </div>
        <?php if($sharedHosting): ?>
            <div class="muted" style="margin-top:8px">
                Shared hosting: backups are created by PHP. Restore is handled by your hosting database tool.
            </div>
        <?php endif ?>

    </div>


    <?php if($canManage): ?>

    <form method="post">

        <input
            type="hidden"
            name="csrf"
            value="<?=h(csrf())?>"
        >

        <input
            type="hidden"
            name="action"
            value="dbbackup-create"
        >

        <button
            type="submit"
            class="green"
        >
            Create Backup Now
        </button>

    </form>

    <?php endif ?>

</div>


<div class="db-backup-grid">

    <div class="card db-backup-stat">

        <small>
            STORED BACKUPS
        </small>

        <div class="db-backup-value">
            <?=count($files)?>
        </div>

    </div>


    <div class="card db-backup-stat">

        <small>
            TOTAL SIZE
        </small>

        <div class="db-backup-value">
            <?=h(
                number_format(
                    $totalSize /
                    1024 /
                    1024,
                    1
                )
            )?> MB
        </div>

    </div>


    <div class="card db-backup-stat">

        <small>
            LATEST BACKUP
        </small>

        <div
            class="db-backup-value"
            style="font-size:18px"
        >
            <?=
                $latest
                ? h(
                    date(
                        'Y-m-d H:i',
                        $latest['mtime']
                    )
                )
                : '—'
            ?>
        </div>

    </div>


    <div class="card db-backup-stat">

        <small>
            RETENTION
        </small>

        <div class="db-backup-value">
            Manual
        </div>

    </div>

</div>


<div class="table">

<table>

<thead>
<tr>
    <th>Date</th>
    <th>File</th>
    <th>Size</th>
    <th>Integrity</th>
    <th>SHA-256</th>
    <th>Actions</th>
</tr>
</thead>


<tbody>

<?php if(!$files): ?>

<tr>
<td
    colspan="6"
    class="muted"
    style="text-align:center;padding:25px"
>
    No database backups found.
</td>
</tr>

<?php endif ?>


<?php foreach($files as $file): ?>

<tr>

<td>
    <?=h(
        date(
            'Y-m-d H:i:s',
            $file['mtime']
        )
    )?>
</td>


<td>
    <b>
        <?=h($file['name'])?>
    </b>
</td>


<td>
    <?=h(
        number_format(
            $file['size'] /
            1024 /
            1024,
            2
        )
    )?> MB
</td>


<td>

<?php if($file['valid']): ?>

    <span class="badge green">
        VERIFIED
    </span>

<?php else: ?>

    <span class="badge red">
        INVALID
    </span>

<?php endif ?>

</td>


<td
    class="db-sha"
    title="<?=h(
        (string)$file['sha']
    )?>"
>
    <?=h(
        substr(
            (string)$file['sha'],
            0,
            16
        )
    )?>…
</td>


<td>

<div class="db-backup-actions">


<?php if($canManage): ?>

<form method="post">

    <input
        type="hidden"
        name="csrf"
        value="<?=h(csrf())?>"
    >

    <input
        type="hidden"
        name="action"
        value="dbbackup-download"
    >

    <input
        type="hidden"
        name="file"
        value="<?=h($file['name'])?>"
    >

    <button
        type="submit"
        class="gray"
    >
        Download
    </button>

</form>

<?php endif ?>


<?php if($isOwner): ?>

<form
    method="post"
    onsubmit="
        return confirm(
            'Delete this database backup permanently?'
        );
    "
>

    <input
        type="hidden"
        name="csrf"
        value="<?=h(csrf())?>"
    >

    <input
        type="hidden"
        name="action"
        value="dbbackup-delete"
    >

    <input
        type="hidden"
        name="file"
        value="<?=h($file['name'])?>"
    >

    <button
        type="submit"
        class="red"
    >
        Delete
    </button>

</form>

<?php endif ?>

</div>


<?php if(
    $isOwner &&
    $file['valid'] &&
    !$sharedHosting
): ?>

<details class="db-restore-box">

<summary>
    Restore this backup
</summary>

<form
    method="post"
    style="margin-top:12px"
    onsubmit="
        return confirm(
            'FINAL WARNING: This will replace the CURRENT database. Continue?'
        );
    "
>

    <input
        type="hidden"
        name="csrf"
        value="<?=h(csrf())?>"
    >

    <input
        type="hidden"
        name="action"
        value="dbbackup-restore"
    >

    <input
        type="hidden"
        name="file"
        value="<?=h($file['name'])?>"
    >

    <small>
        Type exactly:
    </small>

    <div
        class="db-sha"
        style="margin:5px 0 8px"
    >
        RESTORE <?=h($file['name'])?>
    </div>

    <input
        name="restore_confirmation"
        autocomplete="off"
        required
        style="width:100%;margin-bottom:8px"
    >

    <button
        type="submit"
        class="red"
    >
        Restore Database
    </button>

</form>

</details>

<?php endif ?>

</td>

</tr>

<?php endforeach ?>

</tbody>
</table>

</div>

    <?php
}
