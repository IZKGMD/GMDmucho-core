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

echo "SETTINGS_TEST_OK\n";