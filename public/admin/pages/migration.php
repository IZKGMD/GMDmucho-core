<?php
declare(strict_types=1);

$output=$_SESSION['migration_output'] ?? null;
$status=$_SESSION['migration_status'] ?? null;
$statusType=(string)($_SESSION['migration_status_type'] ?? 'ok');
$form=$_SESSION['migration_form'] ?? [];
unset(
    $_SESSION['migration_output'],
    $_SESSION['migration_status'],
    $_SESSION['migration_status_type'],
    $_SESSION['migration_form']
);

$sqlJob=$_SESSION['migration_sql_job'] ?? null;
$sqlPreview=$_SESSION['migration_sql_preview'] ?? null;
$sqlStatus=$_SESSION['migration_sql_status'] ?? null;
$sqlStatusType=(string)($_SESSION['migration_sql_status_type'] ?? 'ok');
$sqlOutput=$_SESSION['migration_sql_output'] ?? null;
unset(
    $_SESSION['migration_sql_status'],
    $_SESSION['migration_sql_status_type'],
    $_SESSION['migration_sql_output']
);

$form=$_SESSION['migration_form'] ?? [];
$oldHost=(string)($form['source_host'] ?? '');
$oldPort=(string)($form['source_port'] ?? '3306');
$oldDb=(string)($form['source_db'] ?? '');
$oldUser=(string)($form['source_user'] ?? '');
?>
<div class="card">
    <div class="row" style="justify-content:space-between;align-items:flex-start">
        <div>
            <h2 style="margin-top:0">FHGDPS / Cvolton Migration</h2>
            <p class="muted" style="max-width:780px;line-height:1.6">
                Move your existing Geometry Dash GDPS database into MuchoCore.
                MuchoCore automatically detects the source schema and imports the datasets it can map safely.
            </p>
        </div>
        <span class="badge">Read-only check first</span>
    </div>

    <?php if ($status !== null): ?>
        <div class="flash <?=($statusType==='error'?'error':'')?>">
            <?=h((string)$status)?>
        </div>
    <?php endif; ?>

    <div class="card" style="background:#0c1119;border-color:#273449;margin:16px 0">
        <h3 style="margin-top:0">What you need from the old GDPS</h3>
        <p class="muted" style="line-height:1.6">
            Enter the old MySQL/MariaDB connection details. Do not enter the website address of the old GDPS.
            The database host is usually listed in the old server configuration or database panel.
        </p>
        <div class="grid" style="margin-top:12px">
            <div>
                <b>Automatically imported</b>
                <div class="muted" style="margin-top:5px">Accounts, profiles, levels and scores</div>
            </div>
            <div>
                <b>Detected and reported</b>
                <div class="muted" style="margin-top:5px">Comments, social data, collections and legacy moderation</div>
            </div>
            <div>
                <b>Files required separately</b>
                <div class="muted" style="margin-top:5px">Old music/ and sfx/ files</div>
            </div>
        </div>
    </div>

    <form method="post">
        <input type="hidden" name="csrf" value="<?=csrf()?>">
        <input type="hidden" name="action" value="migration-preview">

        <div class="grid" style="margin-bottom:12px">
            <label>
                <span class="muted">Old database host</span><br>
                <input name="source_host" value="<?=h($oldHost)?>" placeholder="127.0.0.1" required style="width:100%">
            </label>
            <label>
                <span class="muted">Port</span><br>
                <input name="source_port" value="<?=h($oldPort)?>" placeholder="3306" inputmode="numeric" required style="width:100%">
            </label>
            <label>
                <span class="muted">Database name</span><br>
                <input name="source_db" value="<?=h($oldDb)?>" placeholder="gdps" required style="width:100%">
            </label>
            <label>
                <span class="muted">Database user</span><br>
                <input name="source_user" value="<?=h($oldUser)?>" placeholder="gdps_user" required style="width:100%">
            </label>
        </div>

        <label style="display:block">
            <span class="muted">Database password</span><br>
            <input type="password" name="source_pass" autocomplete="new-password" placeholder="Password for the old database" required style="width:100%">
        </label>

        <div class="row" style="margin-top:14px">
            <button type="submit">Check source</button>
            <button
                type="submit"
                class="green"
                formaction="/admin/"
                name="action"
                value="migration-apply"
                onclick="return confirm('MuchoCore will create and verify a backup before importing supported data. Continue?')"
            >Migrate supported data</button>
        </div>
        <p class="muted" style="font-size:11px;line-height:1.5;margin-bottom:0">
            “Check source” is read-only. Migration creates a verified target backup before changing MuchoCore.
            The old database is never modified by this tool.
        </p>
    </form>
</div>

<div class="card" style="margin-top:14px">
    <div class="row" style="justify-content:space-between;align-items:flex-start">
        <div>
            <h2 style="margin-top:0">SQL File Import</h2>
            <p class="muted" style="max-width:820px;line-height:1.6">
                Have a <b>database.sql</b> instead of live database credentials?
                Upload the dump here. MuchoCore stages supported Cvolton/MegaSa1nt tables under temporary names,
                checks the schema, and only changes the production database after you explicitly start the import.
            </p>
        </div>
        <span class="badge">Staged before import</span>
    </div>

    <?php if ($sqlStatus !== null): ?>
        <div class="flash <?=($sqlStatusType==='error'?'error':'')?>" style="margin-top:12px">
            <?=h((string)$sqlStatus)?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?=csrf()?>">
        <input type="hidden" name="action" value="migration-sql-upload">

        <label style="display:block">
            <span class="muted">database.sql or database.sql.gz</span><br>
            <input
                type="file"
                name="sql_file"
                accept=".sql,.gz,application/sql,application/gzip"
                required
                style="width:100%;padding:10px"
            >
        </label>

        <label style="display:block;margin-top:12px">
            <span class="muted">Galaxxy level-data archive (.zip) — recommended</span><br>
            <input
                type="file"
                name="galaxxy_archive"
                accept=".zip,application/zip"
                style="width:100%;padding:10px"
            >
        </label>

        <p class="muted" style="font-size:11px;line-height:1.5">
            Galaxxy-compatible servers normally keep the playable levelString outside MySQL in
            <code>public_html/data/levels/&lt;levelID&gt;</code>. Attach the old server .zip to restore those level payloads.
            SQL-only imports can restore level metadata but cannot reconstruct missing level data.
        </p>

        <div class="row" style="margin-top:12px">
            <button type="submit" class="green">Upload &amp; Check SQL</button>
        </div>

        <p class="muted" style="font-size:11px;line-height:1.5;margin-bottom:0">
            Maximum upload size: 256 MB per upload. The optional Galaxxy archive is read selectively:
            only <code>public_html/data/levels/&lt;levelID&gt;</code> files are staged. Stored procedures and triggers are intentionally not executed.
        </p>
    </form>
</div>

<?php if (is_array($sqlJob) && is_array($sqlPreview)): ?>
<div class="card" style="margin-top:14px">
    <div class="row" style="justify-content:space-between;align-items:flex-start">
        <div>
            <h2 style="margin-top:0">SQL Dump Ready</h2>
            <p class="muted">
                File: <b><?=h((string)($sqlJob['filename'] ?? 'database.sql'))?></b>
            </p>
        </div>
        <span class="badge green">Schema verified</span>
    </div>

    <?php $p=$sqlPreview['preflight'] ?? []; $i=$sqlPreview['inspection'] ?? []; ?>

    <div class="grid" style="margin:14px 0">
        <div>
            <b>Source family</b>
            <div class="muted" style="margin-top:5px"><?=h((string)($i['label'] ?? 'Unknown'))?></div>
        </div>
        <div>
            <b>Accounts</b>
            <div class="muted" style="margin-top:5px"><?=number_format((int)($p['accounts'] ?? 0))?></div>
        </div>
        <div>
            <b>Profiles</b>
            <div class="muted" style="margin-top:5px"><?=number_format((int)($p['users'] ?? 0))?></div>
        </div>
        <div>
            <b>Levels</b>
            <div class="muted" style="margin-top:5px"><?=number_format((int)($p['levels'] ?? 0))?></div>
        </div>
        <div>
            <b>Classic scores</b>
            <div class="muted" style="margin-top:5px"><?=number_format((int)($p['levelscores'] ?? 0))?></div>
        </div>
        <div>
            <b>Platformer scores</b>
            <div class="muted" style="margin-top:5px"><?=number_format((int)($p['platscores'] ?? 0))?></div>
        </div>
    </div>

    <p class="muted" style="line-height:1.6">
        The SQL dump is currently isolated under temporary staging table names.
        The real MuchoCore tables have not been replaced or cleared.
        <?php $ld=$sqlPreview['level_data'] ?? []; ?>
        <?php if ((int)($ld['stored_files'] ?? 0) > 0): ?>
            Galaxxy level-data archive: <b><?=number_format((int)($ld['stored_files'] ?? 0))?></b> files staged,
            <b><?=number_format((int)($ld['matched_files'] ?? 0))?></b> match the SQL level IDs,
            <?=number_format((int)($ld['matched_bytes'] ?? 0))?> bytes matched.
        <?php else: ?>
            No Galaxxy level-data archive is attached. SQL-only imports leave playable level data empty.
        <?php endif; ?>
    </p>

    <div class="row" style="margin-top:14px">
        <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf()?>">
            <input type="hidden" name="action" value="migration-sql-apply">
            <button
                type="submit"
                class="green"
                onclick="return confirm('MuchoCore will create a fresh verified backup, then import the staged SQL data. Continue?')"
            >Import This SQL Dump</button>
        </form>

        <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf()?>">
            <input type="hidden" name="action" value="migration-sql-discard">
            <button
                type="submit"
                class="gray"
                onclick="return confirm('Discard the staged SQL dump? The production database will not be changed.')"
            >Discard</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (is_string($sqlOutput) && $sqlOutput !== ''): ?>
<div class="card" style="margin-top:14px">
    <div class="row" style="justify-content:space-between">
        <h2 style="margin-top:0">SQL Migration Result</h2>
        <span class="badge">Sensitive data is not displayed</span>
    </div>
    <pre><?=h($sqlOutput)?></pre>
</div>
<?php endif; ?>

<?php if (is_string($output) && $output !== ''): ?>
<div class="card" style="margin-top:14px">
    <div class="row" style="justify-content:space-between">
        <h2 style="margin-top:0">Migration result</h2>
        <span class="badge">Sensitive passwords are not displayed</span>
    </div>
    <pre><?=h($output)?></pre>
</div>
<?php endif; ?>

<div class="card" style="margin-top:14px">
    <h2 style="margin-top:0">Simple migration flow</h2>
    <div class="muted" style="line-height:1.8">
        <b>Live database:</b> enter connection details → Check source → Migrate supported data.<br>
        <b>SQL file:</b> upload database.sql + optional Galaxxy .zip → Upload &amp; Check SQL → review detected counts → Import This SQL Dump.
    </div>
</div>
