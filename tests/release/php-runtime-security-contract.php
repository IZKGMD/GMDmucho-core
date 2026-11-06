<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bin/php-runtime-security.php';

$versions = [
    '8.2.34' => false,
    '8.2.35' => true,
    '8.3.35' => false,
    '8.3.36RC1' => false,
    '8.3.36' => true,
    '8.3.37' => true,
    '8.4.26' => false,
    '8.4.27' => true,
    '8.5.11' => false,
    '8.5.12' => true,
    '7.4.33' => false,
    '8.1.99' => false,
    '8.6.0' => false,
    'unknown' => false,
];

foreach ($versions as $version => $expected) {
    if (muchoPhpMeetsSecurityBaseline($version) !== $expected) {
        throw new RuntimeException('Wrong 2026 PHP security baseline for ' . $version);
    }
}

if (strpos(file_get_contents(dirname(__DIR__, 2) . '/docker/Dockerfile'),
    'RUN php /usr/local/bin/mucho-php-runtime-security.php') === false) {
    throw new RuntimeException('Docker image must validate PHP security release during build.');
}

echo "PHP_RUNTIME_SECURITY_BASELINE_OK\n";
