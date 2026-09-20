<?php
declare(strict_types=1);

/*
 * MuchoCore API v2
 * Copyright (C) 2026 IZK
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use MuchoCore\Core\Settings;

muchoV2RequireMethod('GET');

try {
    $db = muchoV2Db();
    $db->query('SELECT 1')->fetchColumn();

    muchoV2Send([
        'ok' => true,
        'api' => Settings::string('MUCHO_SERVER_NAME', 'MuchoCore'),
        'version' => Settings::string('MUCHO_SERVER_VERSION', MUCHO_V2_VERSION),
        'service' => 'api',
        'database' => 'connected'
    ]);
} catch (Throwable $e) {
    error_log('[MuchoCore API v2] health: ' . $e->getMessage());
    muchoV2Fail('internal_error', 500);
}
