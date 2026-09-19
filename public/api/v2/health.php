<?php
declare(strict_types=1);

/*
 * MuchoCore API v2
 * Copyright (C) 2026 IZK
 */

require_once __DIR__ . '/bootstrap.php';

muchoV2RequireMethod('GET');

try {
    $db = muchoV2Db();
    $db->query('SELECT 1')->fetchColumn();

    muchoV2Send([
        'ok' => true,
        'api' => 'MuchoCore',
        'version' => MUCHO_V2_VERSION,
        'service' => 'api',
        'database' => 'connected'
    ]);
} catch (Throwable $e) {
    error_log('[MuchoCore API v2] health: ' . $e->getMessage());
    muchoV2Fail('internal_error', 500);
}
