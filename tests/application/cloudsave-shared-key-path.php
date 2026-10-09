<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\CloudSave\CloudSaveRepository;

putenv('MUCHO_CLOUDSAVE_KEY_FILE');
unset($_ENV['MUCHO_CLOUDSAVE_KEY_FILE']);
putenv('MUCHO_SHARED_HOSTING=1');
$_ENV['MUCHO_SHARED_HOSTING'] = '1';

$pdo = new class extends PDO {
    public function __construct() {}
};

$repo = new CloudSaveRepository($pdo);
$method = new ReflectionMethod($repo, 'keyPath');
$expected = dirname(__DIR__, 2) . '/config/cloudsave.key';

if ($method->invoke($repo) !== $expected) {
    throw new RuntimeException(
        'Shared-hosting key path must match the installer-created key.'
    );
}

echo "MUCHOCORE_SHARED_CLOUDSAVE_PATH_OK\n";
