<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$tracker = (string)file_get_contents(
    $root . '/src/Level/LevelDownloadTracker.php'
);
$service = (string)file_get_contents(
    $root . '/src/Level/LevelTransferService.php'
);
$controller = (string)file_get_contents(
    $root . '/src/Level/LevelTransferController.php'
);
$migration = (string)file_get_contents(
    $root . '/database/migrations/20261007_001_level_download_dedup.php'
);

$checks = [
    [$tracker, 'hash_hmac(', 'download dedupe hashes client IPs'],
    [$tracker, "Environment::get('MUCHO_DOWNLOAD_HMAC_KEY'", 'download dedupe supports a dedicated HMAC key'],
    [$tracker, "Environment::get('DB_PASS'", 'download dedupe has a stable protected fallback key'],
    [$tracker, 'MUCHO_DOWNLOAD_DEDUP_SECONDS', 'download dedupe window is configurable'],
    [$tracker, 'INSERT IGNORE INTO mucho_level_downloads', 'download slots are claimed atomically'],
    [$tracker, 'for ($slot = 1; $slot <= 2; $slot++)', 'paired client download compatibility is bounded'],
    [$service, '$this->downloadTracker->record(', 'level downloads use the dedupe tracker'],
    [$controller, '$request->clientIp()', 'download controller passes resolved client IP'],
    [$migration, 'UNIQUE KEY uq_mucho_level_download_client_slot', 'download dedupe slots are unique in the database'],
];

foreach ($checks as [$content, $needle, $label]) {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }

    echo "PASS {$label}\n";
}

echo "MUCHOCORE_DOWNLOAD_DEDUPE_OK\n";
