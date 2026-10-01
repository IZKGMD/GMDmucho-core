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

$oldHost=(string)($form['source_host'] ?? '');
$oldPort=(string)($form['source_port'] ?? '3306');
$oldDb=(string)($form['source_db'] ?? '');
$oldUser=(string)($form['source_user'] ?? '');
$sqlToken=(string)($_SESSION['migration_sql_token'] ?? '');
$sqlName=(string)($_SESSION['migration_sql_name'] ?? '');
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

    <?php if ($status !== null): ?>
        <div class="flash <?=($statusType==='error'?'error':'')?>">
            <?=h((string)$status)?>
        </div>
    <?php endif; ?>

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

<div class="card" style="margin-top:16px">
    <div class="row" style="justify-content:space-between;align-items:flex-start">
        <div>
            <h3 style="margin-top:0">Upload database.sql</h3>
            <p class="muted" style="max-width:780px;line-height:1.6;margin-bottom:0">
                Have a database dump instead of access to the old MySQL server?
                Upload the <b>database.sql</b> file here. MuchoCore imports it into an isolated migration database,
                detects the schema, and then uses the same verified Cvolton importer.
            </p>
        </div>
        <span class="badge">VPS / Docker</span>
    </div>

    <?php if ((string)(getenv('MUCHO_SHARED_HOSTING') ?: '') === '1'): ?>
        <div class="flash error" style="margin-top:12px">
            SQL file import is available on VPS/Docker installations. On shared hosting, connect the old MySQL/MariaDB database above.
        </div>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data" style="margin-top:14px">
            <input type="hidden" name="csrf" value="<?=csrf()?>">
            <input type="hidden" name="action" value="migration-sql-preview">
            <label style="display:block">
                <span class="muted">SQL dump</span><br>
                <input type="file" name="source_sql" accept=".sql,application/sql,text/plain" required style="width:100%">
            </label>
            <button type="submit" style="margin-top:12px">Upload &amp; Check</button>
            <p class="muted" style="font-size:11px;line-height:1.5;margin-bottom:0">
                Maximum upload size: 64 MiB. The dump is stored outside the public web root and is cleaned after a successful import.
            </p>
        </form>

        <?php if ($sqlToken !== ''): ?>
            <div style="margin-top:14px;padding:12px;border:1px solid #273449;border-radius:10px">
                <b>Loaded dump:</b> <?=h($sqlName !== '' ? $sqlName : 'database.sql')?>
                <form method="post" style="margin-top:10px" onsubmit="return confirm('MuchoCore will re-import this dump, create and verify a fresh target backup, then migrate supported data. Continue?')">
                    <input type="hidden" name="csrf" value="<?=csrf()?>">
                    <input type="hidden" name="action" value="migration-sql-apply">
                    <input type="hidden" name="sql_token" value="<?=h($sqlToken)?>">
                    <button type="submit" class="green">Migrate uploaded database</button>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

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
        1. Enter the old database details.<br>
        2. Click <b>Check source</b> and review the detected data.<br>
        3. Click <b>Migrate supported data</b>.<br>
        4. MuchoCore backs up the target, imports supported records, verifies the operation and reports anything that still needs manual file access.
    </div>
</div>
