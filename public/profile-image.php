<?php
declare(strict_types=1);

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Appearance\ProfileMediaService;
use MuchoCore\Database\Database;

// Browser-readable counterpart of the existing POST-only in-game image API.
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
$account = $_GET['account'] ?? '';
$kind = $_GET['kind'] ?? '';
$version = $_GET['version'] ?? '';
if (!is_string($account) || preg_match('/^[1-9][0-9]{0,9}$/D', $account) !== 1 || (int)$account > 2147483647
    || !is_string($kind) || !in_array($kind, ['avatar', 'banner'], true)
    || !is_string($version) || preg_match('/^[a-f0-9]{64}$/D', $version) !== 1) {
    http_response_code(404);
    exit;
}
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $db = (new Database())->connection();
    $bytes = (new ProfileMediaService($db, new AccountAuthenticator($db)))->image((int)$account, $kind, $version);
    if ($bytes === null) {
        http_response_code(404);
        exit;
    }
    // Revalidate so removed artwork and banned accounts do not linger in browsers.
    $etag = '"' . $version . '"';
    header('Cache-Control: private, no-cache');
    header('ETag: ' . $etag);
    header('Content-Type: image/jpeg');
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . strlen($bytes));
    if ($method !== 'HEAD') echo $bytes;
} catch (Throwable $error) {
    error_log('[MuchoCore][Web profile image] ' . $error->getMessage());
    http_response_code(404);
}
