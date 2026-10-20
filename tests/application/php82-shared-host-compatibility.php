<?php

declare(strict_types=1);

/**
 * Keep shared-host requirements, Composer, and versioned bootstrap checks
 * synchronized. Real PHP 8.2 execution is tested by the CI PHP matrix.
 */
$root = dirname(__DIR__, 2);
$composer = json_decode(
    (string)file_get_contents($root . '/composer.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$lock = json_decode(
    (string)file_get_contents($root . '/composer.lock'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
if (($composer['require']['php'] ?? null) !== '^8.2'
    || ($lock['platform']['php'] ?? null) !== '^8.2') {
    throw new RuntimeException('Composer minimum must remain PHP 8.2+ in both manifests.');
}
foreach ([
    $root . '/public/shared-install.php',
    $root . '/public/ftp-install.php',
] as $path) {
    $source = (string)file_get_contents($path);
    if (!str_contains($source, "version_compare(PHP_VERSION, '8.2.0', '>=' )")
        && !str_contains($source, "version_compare(PHP_VERSION, '8.2.0', '>=' )")) {
        // Whitespace-tolerant check without evaluating the installer.
        if (!preg_match(
            '/version_compare\\s*\\(\\s*PHP_VERSION\\s*,\\s*[\\x27]8\\.2\\.0[\\x27]\\s*,\\s*[\\x27]>=[\\x27]\\s*\\)/',
            $source
        )) {
            throw new RuntimeException('Shared installer PHP guard is out of sync: ' . basename($path));
        }
    }
}
echo "PHP_82_SHARED_HOSTING_REQUIREMENTS_OK\n";
