<?php

declare(strict_types=1);

use MuchoCore\Branding\BrandingService;

require_once __DIR__ . '/bootstrap.php';

muchoV2RequireMethod('GET');

try {
    $branding = (new BrandingService(muchoV2Db()))->get();

    muchoV2Send([
        'ok' => true,
        'server_name' => $branding['server_name'],
    ]);
} catch (Throwable $e) {
    error_log('[MuchoCore Branding] ' . $e->getMessage());

    muchoV2Send([
        'ok' => true,
        'server_name' => BrandingService::DEFAULT_SERVER_NAME,
    ]);
}
