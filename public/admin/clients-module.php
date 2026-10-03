<?php
declare(strict_types=1);

use MuchoCore\Core\Environment;

function tenantClientStorage(string $rootDir): string
{
    $dir = rtrim($rootDir, '/\\') . '/storage/clients';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Client storage is unavailable.');
    }
    @chmod($dir, 0750);
    return $dir;
}

function tenantClientManifest(string $rootDir): array
{
    $path = tenantClientStorage($rootDir) . '/manifest.json';
    if (!is_file($path)) return [];
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function tenantClientDownload(string $rootDir): never
{
    requireRank(30);

    $kind = strtolower(trim((string)($_GET['client_file'] ?? '')));
    $map = [
        'windows' => ['file' => 'GeometryDash-MuchoGDPS.exe', 'type' => 'application/vnd.microsoft.portable-executable'],
        'android' => ['file' => 'GeometryDash-MuchoGDPS.apk', 'type' => 'application/vnd.android.package-archive'],
        'zip' => ['file' => 'MuchoGDPS-Client-Pack.zip', 'type' => 'application/zip'],
    ];
    if (!isset($map[$kind])) {
        http_response_code(404);
        exit('Client file not found.');
    }

    $dir = tenantClientStorage($rootDir);
    $file = $dir . '/' . $map[$kind]['file'];
    $realDir = realpath($dir);
    $realFile = realpath($file);

    if (
        $realDir === false || $realFile === false ||
        !str_starts_with($realFile, rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) ||
        !is_file($realFile) || !is_readable($realFile)
    ) {
        http_response_code(404);
        exit('Client file not found.');
    }

    $size = filesize($realFile);
    if ($size === false || $size < 1024) {
        http_response_code(404);
        exit('Client file is unavailable.');
    }

    audit($GLOBALS['db'], 'client.download', $map[$kind]['file']);
    header('Content-Type: ' . $map[$kind]['type']);
    header('Content-Disposition: attachment; filename="' . $map[$kind]['file'] . '"');
    header('Content-Length: ' . (string)$size);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');

    if (readfile($realFile) === false) {
        http_response_code(500);
        exit('Unable to read client file.');
    }
    exit;
}

function renderTenantClientsPage(PDO $db): void
{
    $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 2);
    $storage = tenantClientStorage($rootDir);
    $manifest = tenantClientManifest($rootDir);
    $windows = is_array($manifest['windows'] ?? null) ? $manifest['windows'] : [];
    $android = is_array($manifest['android'] ?? null) ? $manifest['android'] : [];
    $serverUrl = (string)($manifest['server_url'] ?? (Environment::get('MUCHO_ACCOUNT_URL', '') ?? ''));
    $serverName = (string)($manifest['server_name'] ?? 'Your GDPS');
    $windowsPath = $storage . '/GeometryDash-MuchoGDPS.exe';
    $androidPath = $storage . '/GeometryDash-MuchoGDPS.apk';
    $archivePath = $storage . '/MuchoGDPS-Client-Pack.zip';
    $windowsExists = is_file($windowsPath);
    $androidExists = is_file($androidPath);
    $archiveExists = is_file($archivePath);
    $archive = is_array($manifest['archive'] ?? null) ? $manifest['archive'] : [];
    ?>
<style>
.mc-tenant-clients{display:grid;gap:14px}.mc-client-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.mc-client-card{padding:20px;border:1px solid #273348;border-radius:16px;background:#0c121d}
.mc-client-card h3{margin:0 0 7px;font-size:15px}.mc-client-card p{margin:0;color:#8794a9;font-size:11px;line-height:1.65}
.mc-client-meta{display:grid;gap:6px;margin-top:14px;color:#7f8ca1;font-size:10px}.mc-client-meta code{color:#bcc8da;word-break:break-all}
.mc-client-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
.mc-client-btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 12px;border:1px solid #33435a;border-radius:9px;background:#101824;color:#dce5f2;text-decoration:none;font-size:10px;font-weight:900}
.mc-client-btn.primary{border-color:#3b8d6b;background:rgba(89,230,164,.08);color:#aaf2cf}
.mc-client-note{padding:12px;border-radius:11px;border:1px solid #2b3748;background:#090f18;color:#79869a;font-size:10px;line-height:1.65}
@media(max-width:800px){.mc-client-grid{grid-template-columns:1fr}}
</style>
<div class="mc-tenant-clients">
<section class="card">
<h2>🎮 Client Downloads</h2>
<p style="color:#9aa7bb;font-size:12px;line-height:1.7">Generated clients for this GDPS are stored on this server in protected storage and are available only to authenticated administrators.</p>
<div class="mc-client-note" style="margin-top:12px"><b><?=h($serverName)?></b><?php if($serverUrl!==''): ?> · <code><?=h($serverUrl)?></code><?php endif; ?></div>
</section>
<div class="mc-client-grid">
<section class="mc-client-card">
<h3>🪟 Windows EXE</h3>
<p><?= $windowsExists ? 'Generated and stored on this server.' : 'No generated Windows client is available yet.' ?></p>
<?php if($windowsExists): ?>
<div class="mc-client-meta">
<div>Size: <?=number_format(((int)($windows['size'] ?? filesize($windowsPath)))/1048576,2)?> MB</div>
<div>SHA-256: <code><?=h((string)($windows['sha256'] ?? hash_file('sha256',$windowsPath)))?></code></div>
</div>
<div class="mc-client-actions"><a class="mc-client-btn primary" href="/admin/?page=clients&client_file=windows">⬇ Download Windows EXE</a></div>
<?php endif; ?>
</section>
<section class="mc-client-card">
<h3>📱 Android APK</h3>
<p><?= $androidExists ? 'Generated, aligned, signed and stored on this server.' : 'No generated Android client is available yet.' ?></p>
<?php if($androidExists): ?>
<div class="mc-client-meta">
<div>Size: <?=number_format(((int)($android['size'] ?? filesize($androidPath)))/1048576,2)?> MB</div>
<div>SHA-256: <code><?=h((string)($android['sha256'] ?? hash_file('sha256',$androidPath)))?></code></div>
</div>
<div class="mc-client-actions"><a class="mc-client-btn primary" href="/admin/?page=clients&client_file=android">⬇ Download Android APK</a></div>
<?php endif; ?>
</section>
</div>
</div>

<?php if($archiveExists): ?>
<section class="mc-client-card" style="margin-top:14px">
<h3>📦 Unified Client Pack</h3>
<p>One ZIP containing the Windows client, Android APK and checksum manifest.</p>
<div class="mc-client-meta">
<div>Size: <?=number_format(((int)($archive['size'] ?? filesize($archivePath)))/1048576,2)?> MB</div>
<div>SHA-256: <code><?=h((string)($archive['sha256'] ?? hash_file('sha256',$archivePath)))?></code></div>
</div>
<div class="mc-client-actions"><a class="mc-client-btn primary" href="/admin/?page=clients&client_file=zip">⬇ Download Client Pack (.zip)</a></div>
</section>
<?php endif; ?>
</div>
<?php
}
