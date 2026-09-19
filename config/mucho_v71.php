<?php
declare(strict_types=1);

/**
 * MuchoCore v7.1 endpoint configuration.
 * Copyright (C) 2026 IZK
 */
return [
    // Geometry Dash expects getAccountURL.php to return the account/data-server domain.
    'account_url' => getenv('MUCHO_ACCOUNT_URL') ?: 'https://muchogdps.space',

    // Keep official content CDN by default; override later for Mucho-hosted music/SFX.
    'custom_content_url' => getenv('MUCHO_CUSTOM_CONTENT_URL') ?: 'https://geometrydashfiles.b-cdn.net',
];
