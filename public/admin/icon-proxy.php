<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$clientIp = \MuchoCore\Http\ClientIp::resolve($_SERVER);
$limiter = new \MuchoCore\Security\RateLimiter(
    sys_get_temp_dir() . '/muchocore-icon-rate'
);

if (!$limiter->allowStrict('icon-proxy|' . $clientIp, 60, 60)) {
    http_response_code(429);
    header('Cache-Control: no-store');
    header('Retry-After: 60');
    exit;
}

$allowed = ['cube','ship','ball','ufo','wave','robot','spider','swing','jetpack'];
$type = strtolower((string)($_GET['type'] ?? 'cube'));
if (!in_array($type, $allowed, true)) {
    $type = 'cube';
}

$id = filter_var($_GET['id'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 10000],
]);
$id = $id === false ? 1 : (int)$id;

$c1 = filter_var($_GET['c1'] ?? 0, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => 106],
]);
$c1 = $c1 === false ? 0 : (int)$c1;

$c2 = filter_var($_GET['c2'] ?? 3, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => 106],
]);
$c2 = $c2 === false ? 3 : (int)$c2;

$root = dirname(__DIR__, 2);
$cacheDir = $root . '/storage/admin-icon-cache';
@mkdir($cacheDir, 0750, true);

$key = hash('sha256', "{$type}:{$id}:{$c1}:{$c2}");
$cache = $cacheDir . '/' . $key . '.png';

if (is_file($cache) && filesize($cache) > 32) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($cache);
    exit;
}

$upstream = 'https://gdicon.oat.zone/icon.png?' . http_build_query([
    'type' => $type,
    'value' => $id,
    'color1' => $c1,
    'color2' => $c2,
]);

$data = false;
$status = 0;
$contentType = '';

if (function_exists('curl_init')) {
    $ch = curl_init($upstream);
    if ($ch !== false) {
        $data = '';
        $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE => 512000,
            CURLOPT_USERAGENT => 'MuchoGDPS-Admin/1.0',
            CURLOPT_HTTPHEADER => ['Accept: image/png'],
            // A limit applied only after curl_exec() is too late: a large
            // upstream response can exhaust PHP memory before validation.
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$data, &$tooLarge): int {
                $bytes = strlen($chunk);
                if ($bytes > 512000 - strlen($data)) {
                    $tooLarge = true;
                    return 0; // Stop the transfer; never keep a partial PNG.
                }
                $data .= $chunk;
                return $bytes;
            },
        ]);
        $complete = curl_exec($ch) !== false;
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if (!$complete || $tooLarge) {
            $data = false;
        }
    }
}

if (
    $status === 200 &&
    is_string($data) &&
    strlen($data) >= 32 &&
    strlen($data) <= 512000 &&
    str_starts_with($data, "\x89PNG\r\n\x1a\n")
) {
    // Publish only fully written images; concurrent requests must never
    // read a partially written cache entry.
    $tempCache = $cache . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tempCache, $data, LOCK_EX) === strlen($data)) {
        @chmod($tempCache, 0640);
        @rename($tempCache, $cache);
    }
    @unlink($tempCache);
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    echo $data;
    exit;
}

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-store');

$label = htmlspecialchars(strtoupper(substr($type, 0, 2)) . ' ' . $id, ENT_QUOTES, 'UTF-8');

echo '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">'
   . '<rect width="128" height="128" rx="24" fill="#151925"/>'
   . '<rect x="8" y="8" width="112" height="112" rx="20" fill="#252b3b" stroke="#59647f" stroke-width="4"/>'
   . '<text x="64" y="70" text-anchor="middle" font-family="sans-serif" font-size="20" fill="#fff">'
   . $label
   . '</text></svg>';
