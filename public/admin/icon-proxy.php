<?php
declare(strict_types=1);

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
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'MuchoGDPS-Admin/1.0',
        CURLOPT_HTTPHEADER => ['Accept: image/png'],
    ]);
    $data = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
}

if (
    $status === 200 &&
    is_string($data) &&
    strlen($data) >= 32 &&
    strlen($data) <= 2000000 &&
    str_starts_with($data, "\x89PNG\r\n\x1a\n")
) {
    @file_put_contents($cache, $data, LOCK_EX);
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
