<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Core/Settings.php';

use MuchoCore\Core\Settings;

$_ENV['MUCHO_TEST_TEXT'] = ' hello ';
$_ENV['MUCHO_TEST_BOOL'] = '0';
$_ENV['MUCHO_TEST_INT'] = '42';

if (Settings::string('MUCHO_TEST_TEXT', 'default') !== 'hello') {
    fwrite(STDERR, "string setting failed\n");
    exit(1);
}

if (Settings::bool('MUCHO_TEST_BOOL', true) !== false) {
    fwrite(STDERR, "bool setting failed\n");
    exit(1);
}

if (Settings::int('MUCHO_TEST_INT', 10, 1, 100) !== 42) {
    fwrite(STDERR, "int setting failed\n");
    exit(1);
}

if (Settings::int('MUCHO_TEST_MISSING', 10, 1, 100) !== 10) {
    fwrite(STDERR, "default setting failed\n");
    exit(1);
}

$overrideDir = sys_get_temp_dir() . '/muchocore-settings-' . bin2hex(random_bytes(4));
if (!mkdir($overrideDir, 0700, true) && !is_dir($overrideDir)) {
    fwrite(STDERR, "override temp directory failed\n");
    exit(1);
}

try {
    $_ENV['MUCHO_CONTROL_DIR'] = $overrideDir;

    file_put_contents(
        $overrideDir . '/settings.json',
        json_encode([
            'MUCHO_TEST_OVERRIDE' => 'from-admin',
        ], JSON_THROW_ON_ERROR)
    );

    if (Settings::string('MUCHO_TEST_OVERRIDE', 'default') !== 'from-admin') {
        fwrite(STDERR, "override setting failed\n");
        exit(1);
    }
} finally {
    @unlink($overrideDir . '/settings.json');
    @rmdir($overrideDir);
}

echo "SETTINGS_TEST_OK\n";