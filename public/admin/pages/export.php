<?php

declare(strict_types=1);

requirePermission('backups.export');

$rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 3);
$backupDir = defined('BACKUP_DIR')
    ? BACKUP_DIR
    : $rootDir . '/storage/backups/admin-v2';
$exportDir = rtrim($backupDir, '/\\') . '/exports';

$result = $_SESSION['gdps_export_result'] ?? null;
unset($_SESSION['gdps_export_result']);

$files = glob($exportDir . '/muchocore-gdps-export_*.zip') ?: [];
usort($files, static fn(string $a, string $b): int =>
    (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0)
);
?>

<style>
.mc-export-hero{padding:20px;border:1px solid var(--border);border-radius:16px;background:linear-gradient(145deg,#121827,#0d121a);margin-bottom:14px}
.mc-export-hero h2{margin:0 0 7px}.mc-export-hero p{margin:0;max-width:820px;color:var(--muted);line-height:1.5}
.mc-export-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:13px}
.mc-export-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:11px;margin-top:14px}
.mc-export-card{padding:15px}
.mc-export-value{font-size:24px;font-weight:850;margin-top:5px}
</style>

<section class="mc-export-hero">
    <span class="hero-eyebrow">MUCHOCORE TRANSFER TOOLS</span>
    <h2>GDPS Export Pack</h2>
    <p>
        Create a verified transfer archive containing a compressed database
        backup, checksum, safe server metadata, migration state and the
        published client manifest. Secrets and SSH credentials are intentionally
        excluded.
    </p>
    <div class="mc-export-actions">
        <form method="post">
            <input type="hidden" name="csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="action" value="gdps-export">
            <button type="submit">Create export package</button>
        </form>
        <a class="btn gray" href="/admin/?page=ops">MuchoOps</a>
        <a class="btn gray" href="/admin/?page=dbbackups">Backup Center</a>
    </div>
</section>

<?php if (is_array($result)): ?>
<div class="card">
    <b>Export created:</b>
    <span class="ops-code"><?=h((string)$result['file'])?></span>
    · <?=h(number_format((int)$result['size'] / 1024 / 1024, 2))?> MB
    <div style="margin-top:8px">
        <a class="btn" href="/admin/?gdps_export_download=<?=rawurlencode((string)$result['file'])?>">
            Download export
        </a>
    </div>
</div>
<?php endif; ?>

<div class="mc-export-grid">
    <div class="card mc-export-card">
        <small>ARCHIVES</small>
        <div class="mc-export-value"><?=h((string)count($files))?></div>
        <div class="muted">local export packages</div>
    </div>
    <div class="card mc-export-card">
        <small>OUTPUT</small>
        <div class="mc-export-value">ZIP + SHA-256</div>
        <div class="muted">verified archive checksum</div>
    </div>
</div>

<section class="card" style="margin-top:14px">
    <h2 style="margin-top:0">Included</h2>
    <table class="table">
        <thead><tr><th>Path</th><th>Purpose</th></tr></thead>
        <tbody>
            <tr><td><code>database/</code></td><td>Verified compressed SQL backup and checksum</td></tr>
            <tr><td><code>clients/</code></td><td>Published client manifest when available</td></tr>
            <tr><td><code>configuration/</code></td><td>Safe server metadata and <code>.env.example</code></td></tr>
            <tr><td><code>migrations/</code></td><td>Schema migration state at export time</td></tr>
            <tr><td><code>README.txt</code></td><td>Restore/transfer instructions</td></tr>
        </tbody>
    </table>
</section>

<?php if ($files !== []): ?>
<section class="card" style="margin-top:14px">
    <h2 style="margin-top:0">Previous exports</h2>
    <div class="table">
        <table>
            <thead><tr><th>Archive</th><th>Size</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($files as $file): ?>
                <tr>
                    <td><code><?=h(basename($file))?></code></td>
                    <td><?=h(number_format((int)(filesize($file) ?: 0) / 1024 / 1024, 2))?> MB</td>
                    <td><?=h(gmdate('Y-m-d H:i:s', (int)(filemtime($file) ?: time())))?> UTC</td>
                    <td><a class="btn gray" href="/admin/?gdps_export_download=<?=rawurlencode(basename($file))?>">Download</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
