<?php

declare(strict_types=1);

use MuchoCore\Branding\BrandingService;
use MuchoCore\Database\Database;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

try {
    $db = (new Database())->connection();
    $branding = (new BrandingService($db))->get();

    echo json_encode(
        [
            'ok' => true,
            'server_name' => $branding['server_name'],
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );
} catch (Throwable $e) {
    http_response_code(200);

    echo json_encode(
        [
            'ok' => true,
            'server_name' => BrandingService::DEFAULT_SERVER_NAME,
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    error_log('[MuchoCore Branding] ' . $e->getMessage());
}
