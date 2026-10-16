<?php
declare(strict_types=1);

require __DIR__ . '/_native_boot.php';
try {
    $version = $nativeNewsService->nativeVersion();
} catch (Throwable $e) {
    error_log('[MuchoCore News Version] ' . $e::class . ': ' . $e->getMessage());
    http_response_code(503);
    exit;
}
header('Content-Type: text/plain; charset=us-ascii');
header('Cache-Control: public, max-age=15');
echo $version;
