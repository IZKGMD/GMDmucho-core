<?php
declare(strict_types=1);

/*
 * MuchoCore API v2
 * Copyright (C) 2026 IZK
 */

require_once __DIR__ . '/bootstrap.php';

muchoV2RequireMethod('GET');

muchoV2Send([
    'ok' => true,
    'api' => 'MuchoCore',
    'version' => MUCHO_V2_VERSION,
    'endpoints' => [
        '/api/v2/health.php',
        '/api/v2/profile.php?account_id=19'
    ]
]);
