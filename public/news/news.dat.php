<?php
declare(strict_types=1);
use MuchoCore\News\NativeNewsFormat;
require __DIR__ . '/_native_boot.php';
try {
    $encoded = NativeNewsFormat::encode($nativeNewsPosts);
} catch (Throwable $e) {
    error_log('[MuchoCore Native News Data] ' . $e::class . ': ' . $e->getMessage());
    http_response_code(503);
    header('Cache-Control: no-store');
    exit;
}
header('Content-Type: text/plain; charset=us-ascii');
header('Cache-Control: public, max-age=30');
echo $encoded;
