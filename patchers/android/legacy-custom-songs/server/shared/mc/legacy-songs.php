<?php

declare(strict_types=1);

use MuchoCoreLegacySongs\NetworkCatalog;

require_once __DIR__ . '/compat/php7/bootstrap.php';
require_once __DIR__ . '/compat/php7/core-update.php';
require_once __DIR__ . '/compat/php7/legacy-songs-library.php';

function muchoLegacySharedSend(int $status, array $body)
{
    $encoded = json_encode($body, JSON_UNESCAPED_SLASHES);
    http_response_code($encoded === false ? 500 : $status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $encoded === false ? '{"error":"invalid_response"}' : $encoded;
    exit;
}

if (!mucho7_https()) {
    muchoLegacySharedSend(403, array('error' => 'https_required'));
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    muchoLegacySharedSend(405, array('error' => 'method_not_allowed'));
}
$operation = $_GET['op'] ?? '';
if (!in_array($operation, array('catalog', 'track', 'level'), true)) {
    muchoLegacySharedSend(404, array('error' => 'unknown_operation'));
}
if (!is_file(__DIR__ . '/public/php7-mode.on') || !is_file(__DIR__ . '/config/php7-local.php')) {
    muchoLegacySharedSend(503, array('error' => 'core_unavailable'));
}
$script = $_SERVER['SCRIPT_NAME'] ?? '';
if (!is_string($script) || !preg_match('~^((?:/[A-Za-z0-9_-]+)*)/legacy-songs\.php$~D', $script, $matches)) {
    muchoLegacySharedSend(503, array('error' => 'invalid_mount'));
}
$mount = $matches[1];

try {
    $lock = mucho7_core_lock(__DIR__, false);
    if (is_file(__DIR__ . '/config/php7-core-transition.json') || is_file(__DIR__ . '/config/maintenance.flag')) {
        muchoLegacySharedSend(503, array('error' => 'core_unavailable'));
    }
    $settings = mucho7_load_settings(__DIR__ . '/config/php7-local.php');
    $catalog = new NetworkCatalog(mucho7_connect($settings), array(
        $mount . '/public/assets/music/' => __DIR__ . '/public/assets/music',
        $mount . '/music/' => __DIR__ . '/storage/music-public',
    ));
    list($status, $body) = $catalog->handle($operation, $_GET);
    muchoLegacySharedSend($status, $body);
} catch (Throwable $error) {
    error_log('[MuchoCore Legacy Songs] shared catalog request failed: ' . get_class($error));
    muchoLegacySharedSend(503, array('error' => 'core_unavailable'));
}
