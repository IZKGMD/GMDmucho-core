<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use MuchoCore\Database\Database;
use MuchoCore\News\NewsService;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
$root = dirname(__DIR__, 2);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}
try {
    $nativeNewsService = new NewsService((new Database())->connection());
    $nativeNewsPosts = $nativeNewsService->published(30);
} catch (Throwable $e) {
    error_log('[MuchoCore Native News] ' . $e::class . ': ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit;
}
