<?php
/**
 * MuchoCore Shared: single-archive browser entrypoint.
 *
 * This file deliberately uses PHP 7.0 syntax so even older shared hosts
 * display an honest diagnostic instead of a blank HTTP 500/parse error.
 * The complete core remains PHP 8.2+; no PHP 7 installation is attempted.
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
$method = strtoupper((string) $_SERVER['REQUEST_METHOD']);
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Unsupported installation request.');
}

$root = dirname(__DIR__);
$requirements = array();
$requirements[] = array(
    'label' => 'PHP 8.2 or newer (full MuchoCore core)',
    'ok' => PHP_VERSION_ID >= 80200,
    'actual' => 'PHP ' . PHP_VERSION
);
foreach (array('pdo', 'pdo_mysql', 'openssl', 'json', 'mbstring', 'session', 'zlib') as $extension) {
    $requirements[] = array(
        'label' => 'PHP extension ' . $extension,
        'ok' => extension_loaded($extension),
        'actual' => extension_loaded($extension) ? 'available' : 'missing'
    );
}
foreach (array('flock', 'random_bytes', 'password_hash', 'gzopen', 'gzwrite', 'rename') as $function) {
    $requirements[] = array(
        'label' => 'PHP function ' . $function,
        'ok' => function_exists($function),
        'actual' => function_exists($function) ? 'available' : 'disabled'
    );
}
foreach (array(
    $root . '/vendor/autoload.php' => 'Bundled Composer dependencies',
    $root . '/database/migrations' => 'Database migrations',
    $root . '/public/shared-install.php' => 'Shared installer',
    $root . '/public/index.php' => 'GDPS HTTP application',
    $root . '/.htaccess' => 'Root Apache routing'
) as $path => $label) {
    $requirements[] = array(
        'label' => $label,
        'ok' => is_readable($path),
        'actual' => is_readable($path) ? 'included' : 'missing'
    );
}

$https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
    || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');
$requirements[] = array(
    'label' => 'HTTPS connection (required for setup credentials)',
    'ok' => $https,
    'actual' => $https ? 'enabled' : 'not detected'
);
$ready = true;
foreach ($requirements as $item) {
    if (!$item['ok']) {
        $ready = false;
    }
}
// An installer page can explain PHP 7 failure, but can never accept secrets or
// parse/include PHP 8-only source on PHP 7.
if ($ready) {
    require __DIR__ . '/shared-install.php';
    exit;
}
if ($method === 'POST') {
    http_response_code(503);
    exit('Installation blocked: shared-hosting requirements are not satisfied.');
}
function mucho_shared_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MuchoCore Shared — Hosting preflight</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#0d1021;color:#e8eafa;font:15px system-ui,-apple-system,Segoe UI,sans-serif;padding:40px 16px}
main{margin:auto;max-width:820px}h1{font-size:clamp(24px,4vw,36px);margin:8px 0}p{line-height:1.6;color:#bfc7e3}
small{color:#a8b1d0}.card{background:#191d36;border:1px solid #353c62;border-radius:16px;padding:24px;margin-top:20px}
.check{display:flex;justify-content:space-between;gap:14px;padding:11px 0;border-bottom:1px solid #303652}
.check:last-child{border:0}.fail{color:#ffabb0}.pass{color:#9beab8}.status{font-weight:700}
code{color:#e3daff;word-break:break-word}strong{color:#fff}a{color:#c1b8ff}
</style>
</head><body><main>
<small>MUCHOCORE · SHARED HOSTING INSTALLER</small>
<h1>Check your hosting environment</h1>
<p>This archive contains the full MuchoCore shared-hosting server, vendor dependencies,
migrations and browser installer. No VPS or Docker is required when the hosting PHP
and database meet the requirements.</p>
<div class="card">
<h2>Host compatibility</h2>
<?php foreach ($requirements as $item): ?>
<div class="check">
<span><?php echo mucho_shared_html($item['label']); ?></span>
<span class="status <?php echo $item['ok'] ? 'pass' : 'fail'; ?>"><?php echo $item['ok'] ? 'PASS' : 'BLOCKED'; ?> · <?php echo mucho_shared_html($item['actual']); ?></span>
</div>
<?php endforeach; ?>
</div>
<div class="card">
<h2>Why installation is blocked</h2>
<p><strong>Nothing has been installed or changed in your database.</strong>
Enable the missing extensions, upload the complete ZIP contents, and open this page
through HTTPS. Then reload <code>/install.php</code> to start database and admin setup.</p>
<?php if (PHP_VERSION_ID < 80200): ?>
<p>PHP <?php echo mucho_shared_html(PHP_VERSION); ?> cannot run the complete MuchoCore
application. The modern classes and bundled passkey dependency require PHP 8+ syntax.
For native hosting, select PHP 8.2+ in your hosting panel or choose another
provider. A PHP 7 forwarding gateway is <em>not</em> a native server.</p>
<?php endif; ?>
<p>Installation documentation: <code>docs/SHARED_HOSTING.md</code>.</p>
</div>
</main></body></html>
