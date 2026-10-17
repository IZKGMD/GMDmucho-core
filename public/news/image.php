<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use MuchoCore\Database\Database;
use MuchoCore\News\NewsImageResponse;

require_once dirname(__DIR__,2).'/vendor/autoload.php';
$root=dirname(__DIR__,2);
if (is_file($root.'/.env')) Dotenv::createImmutable($root)->safeLoad();
try {
    $filename=$_GET['file'] ?? '';
    if (is_string($filename) && preg_match('~^/(?:gd-content/)?news/(mcnews_[a-f0-9]{64}\.jpg)$~D',$filename,$match)) $filename=$match[1];
    NewsImageResponse::send((new Database())->connection(),is_string($filename)?$filename:'');
} catch (Throwable $error) {
    error_log('[MuchoCore News Image] '.$error->getMessage());
    header('Cache-Control: no-store');http_response_code(503);exit;
}
