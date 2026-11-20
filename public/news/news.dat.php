<?php
declare(strict_types=1);
use MuchoCore\News\NativeNewsFormat;
require __DIR__ . '/_native_boot.php';
try {
    $encoded = NativeNewsFormat::encode($nativeNewsService->published(30));
} catch (Throwable $e) {
    error_log('[MuchoCore Native News Data] ' . $e::class . ': ' . $e->getMessage());
    http_response_code(503);
    exit;
}
header('Content-Type: text/plain; charset=us-ascii');
echo $encoded;
