<?php

declare(strict_types=1);

/**
 * Regression: both web patchers advertised 512 MB, but an accidental
 * 51512 * 1024 bound rejected ordinary 160 MB APKs before upload.
 *
 * Total file limit is 512 MiB, while adaptive upload chunks are bounded
 * between 512 KiB and 8 MiB to fit PHP/Caddy request limits.
 */
$root = dirname(__DIR__, 2);
$maxBytes = 512 * 1024 * 1024;

if (!(160 * 1024 * 1024 <= $maxBytes && $maxBytes + 1 > $maxBytes)) {
    throw new RuntimeException('Incorrect 512 MiB boundary.');
}

foreach ([
    'public/admin/android-client-patcher-module.php',
    'public/admin/client-patcher-module.php',
] as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    if ($source === false) {
        throw new RuntimeException('Cannot read patcher: ' . $relative);
    }

    // One check on the PHP upload-start action, another in the browser JS.
    if (preg_match_all(
        '/(?:\\$totalSize|selected\\.size)\\s*>\\s*512\\s*\\*\\s*1024\\s*\\*\\s*1024/',
        $source
    ) !== 2) {
        throw new RuntimeException('Wrong upload max in PHP or JS: ' . $relative);
    }
    if (str_contains($source, '51512 * 1024')) {
        throw new RuntimeException('The old 50 MiB typo remains: ' . $relative);
    }
    if (!str_contains($source, 'const MIN_CHUNK_SIZE = 512 * 1024;') ||
        !str_contains($source, 'const MAX_CHUNK_SIZE = 8 * 1024 * 1024;') ||
        !str_contains($source, "'chunk_size' => 8 * 1024 * 1024") ||
        !str_contains($source, '$chunkSize > 8 * 1024 * 1024')) {
        throw new RuntimeException('Adaptive chunk negotiation mismatch: ' . $relative);
    }
    if (!str_contains($source, "-status')") ||
        !str_contains($source, "-status',")) {
        throw new RuntimeException('Upload status recovery missing: ' . $relative);
    }
}
echo "admin-client-patcher-upload-limit: OK\\n";
