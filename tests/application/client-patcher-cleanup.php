<?php

declare(strict_types=1);

// Load only the action definitions; no admin session or network is required.
require dirname(__DIR__, 2) . '/public/admin/client-patcher-module.php';

function cleanupAssert(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}

$dir = sys_get_temp_dir() . '/mucho-client-cleanup-' . bin2hex(random_bytes(6));
cleanupAssert(mkdir($dir, 0700), 'Cannot create cleanup fixture');

try {
    $oldWindows = $dir . '/expired.upload';
    $oldApk = $dir . '/active.apk.upload';
    $oldApkMarker = $dir . '/active.apk.upload.part-2.done';
    $fresh = $dir . '/recent.upload';
    foreach ([$oldWindows, $oldApk, $oldApkMarker, $fresh] as $path) {
        cleanupAssert(file_put_contents($path, 'x') === 1, 'Fixture write failed');
    }
    touch($oldWindows, time() - 7200);
    touch($oldApk, time() - 7200);
    touch($oldApkMarker, time() - 7200);

    cleanupClientPatcherFiles($dir);
    cleanupAssert(!file_exists($oldWindows), 'Expired Windows upload should be removed');
    cleanupAssert(is_file($oldApk) && is_file($oldApkMarker),
        'Valid multi-hour APK session must be preserved');
    cleanupAssert(is_file($fresh), 'Recent Windows upload must survive');
    cleanupAssert(is_file($dir . '/.cleanup.lock'), 'Cleanup stamp should be persisted');

    $moreExpired = $dir . '/should-wait.upload';
    file_put_contents($moreExpired, 'x');
    touch($moreExpired, time() - 7200);
    cleanupClientPatcherFiles($dir);
    cleanupAssert(file_exists($moreExpired),
        'A second request must not scan the entire upload directory');

    // Simulate a housekeeping interval elapsing.
    touch($dir . '/.cleanup.lock', time() - 310);
    clearstatcache(true, $dir . '/.cleanup.lock');
    cleanupClientPatcherFiles($dir);
    cleanupAssert(!file_exists($moreExpired), 'Expired files must eventually be reclaimed');

    echo "MUCHOCORE_CLIENT_CLEANUP_RATE_LIMIT_OK\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @unlink($dir . '/.cleanup.lock');
    @rmdir($dir);
}
