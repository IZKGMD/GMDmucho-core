<?php
declare(strict_types=1);

/*
 * MuchoCore Site Builder API
 * Copyright (C) 2026 IZK
 */

require_once __DIR__ . '/bootstrap.php';

use MuchoCore\Core\Settings;

muchoV2RequireMethod('GET');

function muchoSiteUrl(string $key): string
{
    $value = Settings::string($key, '');

    if (
        $value === '' ||
        filter_var($value, FILTER_VALIDATE_URL) === false ||
        !preg_match('~^https://~i', $value)
    ) {
        return '';
    }

    return $value;
}

try {
    $site = [
        'name' => Settings::string('MUCHO_SITE_NAME', 'Mucho GDPS'),
        'tagline' => Settings::string('MUCHO_SITE_TAGLINE', 'Powered by MuchoCore'),
        'description' => Settings::string(
            'MUCHO_SITE_DESCRIPTION',
            'Custom Geometry Dash private server powered by MuchoCore.'
        ),
        'logo' => Settings::string('MUCHO_SITE_LOGO', 'MuchoGDPS'),
        'accent' => Settings::string('MUCHO_SITE_ACCENT', '#7768ff'),
        'accent2' => Settings::string('MUCHO_SITE_ACCENT2', '#43d7cf'),
        'links' => [
            'github' => muchoSiteUrl('MUCHO_SITE_GITHUB_URL'),
            'discord' => muchoSiteUrl('MUCHO_SITE_DISCORD_URL'),
            'telegram' => muchoSiteUrl('MUCHO_SITE_TELEGRAM_URL'),
            'client' => muchoSiteUrl('MUCHO_SITE_CLIENT_URL'),
        ],
        'copyright' => Settings::string(
            'MUCHO_SITE_COPYRIGHT',
            'Copyright © 2026 IZK'
        ),
    ];

    $hex = static fn(string $value, string $fallback): string =>
        preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? $value
            : $fallback;

    $site['accent'] = $hex($site['accent'], '#7768ff');
    $site['accent2'] = $hex($site['accent2'], '#43d7cf');

    muchoV2Send([
        'ok' => true,
        'builder' => 'MuchoCore Site Builder',
        'builder_version' => '1',
        'site' => $site,
        'core' => [
            'name' => 'MuchoCore',
            'copyright' => 'Copyright © 2026 IZK',
            'license' => 'MIT',
        ],
    ]);
} catch (Throwable $e) {
    error_log('[MuchoCore Site Builder] ' . $e->getMessage());
    muchoV2Fail('internal_error', 500);
}
