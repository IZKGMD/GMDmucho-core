<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$clientDir = $root . '/storage/clients';
$manifestPath = $clientDir . '/manifest.json';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$manifest = is_file($manifestPath)
    ? json_decode((string)@file_get_contents($manifestPath), true)
    : null;

if (!is_array($manifest)) {
    http_response_code(503);
    exit('Client downloads are not ready yet.');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function client_file(array $entry, string $clientDir): string
{
    $name = basename((string)($entry['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Invalid client file.');
    }

    $path = $clientDir . '/' . $name;
    $realPath = realpath($path);
    $realDir = realpath($clientDir);

    if (
        $realPath === false ||
        $realDir === false ||
        !str_starts_with($realPath, rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) ||
        !is_file($realPath) ||
        !is_readable($realPath)
    ) {
        throw new RuntimeException('Client file is unavailable.');
    }

    return $realPath;
}

$kind = strtolower(trim((string)($_GET['download'] ?? '')));
if ($kind !== '') {
    $entries = [
        'windows' => $manifest['windows'] ?? null,
        'android' => $manifest['android'] ?? null,
        'zip' => $manifest['archive'] ?? null,
    ];

    if (!isset($entries[$kind]) || !is_array($entries[$kind])) {
        http_response_code(404);
        exit('Unknown client download.');
    }

    try {
        $file = client_file($entries[$kind], $clientDir);
        $size = @filesize($file);
        if ($size === false || $size < 1024) {
            throw new RuntimeException('Client file is unavailable.');
        }

        $name = basename((string)($entries[$kind]['name'] ?? ''));
        $type = match ($kind) {
            'windows' => 'application/vnd.microsoft.portable-executable',
            'android' => 'application/vnd.android.package-archive',
            default => 'application/zip',
        };

        header('Content-Type: ' . $type);
        header('Content-Length: ' . (string)$size);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        readfile($file);
        exit;
    } catch (Throwable $e) {
        http_response_code(404);
        exit('Client file is unavailable.');
    }
}

$serverName = trim((string)($manifest['server_name'] ?? 'Mucho GDPS'));
$serverUrl = trim((string)($manifest['server_url'] ?? ''));
$files = [
    'windows' => [
        'label' => 'Windows Client',
        'description' => 'Ready-to-play patched Geometry Dash client for Windows.',
        'entry' => is_array($manifest['windows'] ?? null) ? $manifest['windows'] : [],
    ],
    'android' => [
        'label' => 'Android APK',
        'description' => 'Ready-to-install patched Geometry Dash APK for Android.',
        'entry' => is_array($manifest['android'] ?? null) ? $manifest['android'] : [],
    ],
    'zip' => [
        'label' => 'Client Pack',
        'description' => 'ZIP with the Windows client, Android APK and checksum manifest.',
        'entry' => is_array($manifest['archive'] ?? null) ? $manifest['archive'] : [],
    ],
];

function size_label(mixed $value): string
{
    $size = max(0, (int)$value);
    if ($size >= 1024 * 1024 * 1024) {
        return number_format($size / 1024 / 1024 / 1024, 2) . ' GB';
    }
    if ($size >= 1024 * 1024) {
        return number_format($size / 1024 / 1024, 1) . ' MB';
    }
    return number_format($size / 1024, 0) . ' KB';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($serverName) ?> — Client Downloads</title>
<style>
:root{--bg:#070a11;--panel:#0e141f;--line:#202b3b;--text:#f4f7ff;--muted:#95a2b6;--accent:#8c7dff;--good:#69e2a8}
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(900px 500px at 20% -10%,rgba(140,125,255,.16),transparent 65%),var(--bg);color:var(--text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{width:min(980px,calc(100% - 28px));margin:auto;padding:52px 0 72px}.eyebrow{display:inline-block;border:1px solid rgba(105,226,168,.18);background:rgba(105,226,168,.07);color:#bdeed5;border-radius:999px;padding:7px 11px;font-size:11px;font-weight:800}.hero{margin:18px 0 28px}.hero h1{font-size:clamp(34px,6vw,62px);letter-spacing:-2.5px;margin:0 0 10px;line-height:1}.hero p{max-width:720px;color:var(--muted);line-height:1.7}.server{color:#cfcaff;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.card{padding:19px;border:1px solid var(--line);border-radius:16px;background:linear-gradient(180deg,#101725,#0c111a)}.card h2{font-size:15px;margin:0 0 8px}.card p{color:var(--muted);font-size:11px;line-height:1.65;min-height:54px}.meta{color:#76849b;font-size:10px;margin:10px 0 14px}.btn{display:inline-block;width:100%;text-align:center;padding:11px 14px;border-radius:10px;border:1px solid rgba(140,125,255,.4);background:linear-gradient(135deg,#8e80ff,#7567f1);color:white;font-weight:900;font-size:11px}.good{margin-top:24px;padding:15px;border:1px solid rgba(105,226,168,.16);border-radius:13px;background:rgba(105,226,168,.05);color:#9ccdb4;font-size:10px;line-height:1.7}.note{margin-top:24px;color:#65738a;font-size:10px;line-height:1.7}.hash{margin-top:10px;color:#718098;font:8px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;word-break:break-all}@media(max-width:760px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="wrap">
<div class="eyebrow">MuchoGDPS · Player Downloads</div>
<section class="hero">
<h1><?= e($serverName) ?> clients.</h1>
<p>These clients are already patched for this GDPS. Players do not need a launcher or updater: download the client and connect directly.</p>
<?php if ($serverUrl !== ''): ?><div class="server"><?= e($serverUrl) ?></div><?php endif; ?>
</section>

<section class="grid">
<?php foreach ($files as $kind => $file): ?>
    <?php $entry = $file['entry']; ?>
    <article class="card">
        <h2><?= e($file['label']) ?></h2>
        <p><?= e($file['description']) ?></p>
        <div class="meta"><?= e(size_label($entry['size'] ?? 0)) ?> · Auto Patch 2.0</div>
        <a class="btn" href="/clients/?download=<?= rawurlencode($kind) ?>">Download</a>
        <?php if (!empty($entry['sha256'])): ?>
            <div class="hash">SHA-256: <?= e((string)$entry['sha256']) ?></div>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
</section>

<div class="good"><strong>Server connection is built in.</strong> These files are generated by MuchoCore for this GDPS and contain the server address configured by its owner.</div>
<div class="note">Keep the downloaded files from this page. The GDPS owner can regenerate them from the MuchoCore control panel when the server address or client source changes.</div>
</main>
</body>
</html>
