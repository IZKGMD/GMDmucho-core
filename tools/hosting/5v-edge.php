<?php
/**
 * Optional PHP 7-compatible GD API relay for 5v.pl or similar shared hosting.
 *
 * IMPORTANT: MuchoCore itself still runs on a separate PHP 8.3+ origin.
 * This file is a GD-protocol HTTP bridge, not a full core or admin interface.
 * Do not use with untrusted origins or without HTTPS end-to-end.
 */
declare(strict_types=1);

$origin = 'https://muchogdps.space'; // Change to your OWN MuchoCore HTTPS origin.
$origin = rtrim($origin, '/');

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
$path = parse_url($uri, PHP_URL_PATH);
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

// Health must reach the real MuchoCore backend. A static "1" here
// incorrectly reported success when the VPS was offline.
$health = $path === '/health' || $path === '/health/';
$gdApiPath = is_string($path) &&
    preg_match(
        '~^/(?:a/|api/)*(?:(?:database/)(?:accounts/)?|accounts/)[A-Za-z][A-Za-z0-9]{0,70}\.php$~iD',
        $path
    ) === 1;
if ((!$gdApiPath && !$health) ||
    ($method !== 'GET' && $method !== 'POST') ||
    ($health && $method !== 'GET')) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo '-1';
    exit;
}

if (!function_exists('curl_init')) {
    http_response_code(503);
    header('Content-Type: text/plain');
    echo '-1';
    exit;
}

// This example is deliberately bounded for 5v's 128 MB PHP memory limit.
// Larger payloads must use a streaming implementation and confirmed host quota.
$maxBody = 8 * 1024 * 1024;
$length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($length < 0 || $length > $maxBody) {
    http_response_code(413);
    header('Content-Type: text/plain');
    echo '-1';
    exit;
}

$body = '';
if ($method === 'POST') {
    $input = fopen('php://input', 'rb');
    if ($input === false) {
        http_response_code(500);
        echo '-1';
        exit;
    }
    $body = stream_get_contents($input, $maxBody + 1);
    fclose($input);
    if (!is_string($body) || strlen($body) > $maxBody) {
        http_response_code(413);
        echo '-1';
        exit;
    }
}

$contentType = isset($_SERVER['CONTENT_TYPE'])
    ? (string) $_SERVER['CONTENT_TYPE']
    : 'application/x-www-form-urlencoded';
if (!preg_match('~^[A-Za-z0-9.+/-]+(?:;\s*charset=[A-Za-z0-9-]+)?$~D', $contentType)) {
    $contentType = 'application/x-www-form-urlencoded';
}

// Target is fully fixed by the operator. Requests cannot choose an upstream.
$target = $origin . $uri;
$ch = curl_init($target);
if ($ch === false) {
    http_response_code(503);
    echo '-1';
    exit;
}
$options = array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => array(
        'Content-Type: ' . $contentType,
        'Accept: */*',
        'Accept-Encoding: identity',
        'User-Agent: MuchoCore-5v-Edge/0.1'
    ),
);
if ($method === 'POST') {
    $options[CURLOPT_POST] = true;
    $options[CURLOPT_POSTFIELDS] = $body;
}
curl_setopt_array($ch, $options);
$response = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if (!is_string($response) || $status < 200 || $status >= 400) {
    http_response_code(502);
    header('Content-Type: text/plain');
    echo '-1';
    exit;
}
if (strlen($response) > 12 * 1024 * 1024) {
    http_response_code(502);
    echo '-1';
    exit;
}
// The GD wire response must not be wrapped in HTML, redirected or decorated.
http_response_code($status);
header('Content-Type: text/plain; charset=utf-8');
echo $response;
