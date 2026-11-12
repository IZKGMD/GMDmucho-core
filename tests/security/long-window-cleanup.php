<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Security/RateLimitBackend.php';
require_once dirname(__DIR__, 2) . '/src/Security/RateLimiter.php';
require_once dirname(__DIR__, 2) . '/src/Security/DatabaseRateLimiter.php';

use MuchoCore\Security\DatabaseRateLimiter;
use MuchoCore\Security\RateLimiter;

$databaseCleanup = new ReflectionMethod(DatabaseRateLimiter::class, 'cleanup');
if ($databaseCleanup->getParameters()[0]->getDefaultValue() < 86400) {
    throw new RuntimeException('Database limiter can expire the 24-hour UDID budget early.');
}

$directory = sys_get_temp_dir() . '/muchocore-daily-limit-' . bin2hex(random_bytes(8));
$limiter = new RateLimiter($directory);

// A valid 24-hour device quota is still active after two hours of inactivity.
// Before the fix, automatic cleanup used a one-hour TTL and reset this quota.
$bucketKey = 'registration-device-daily-budget';
$bucketFile = $directory . '/' . hash('sha256', $bucketKey) . '.json';
$oldTimestamp = time() - 7200;

try {
    if (!$limiter->allowStrict($bucketKey, 2, 86400) ||
        !$limiter->allowStrict($bucketKey, 2, 86400)) {
        throw new RuntimeException('Daily registration bucket setup failed.');
    }

    $state = json_decode((string)file_get_contents($bucketFile), true, 512, JSON_THROW_ON_ERROR);
    $state['start'] = $oldTimestamp;
    if (file_put_contents($bucketFile, json_encode($state, JSON_THROW_ON_ERROR)) === false ||
        !touch($bucketFile, $oldTimestamp)) {
        throw new RuntimeException('Could not age a rate-limit fixture.');
    }

    $limiter->cleanup(3600);
    clearstatcache();

    if (!is_file($bucketFile)) {
        throw new RuntimeException('Explicit short cleanup deleted an active daily quota.');
    }

    if ($limiter->allowStrict($bucketKey, 2, 86400)) {
        throw new RuntimeException('Daily registration quota reset before its 24-hour expiry.');
    }

    // Also verify normal automatic cleanup keeps one-day quotas intact.
    touch($bucketFile, $oldTimestamp);
    $limiter->cleanup();
    clearstatcache();

    if (!is_file($bucketFile)) {
        throw new RuntimeException('Default cleanup deleted an active daily quota.');
    }

    // Expired buckets are still collected; the fix must not disable GC.
    $expiredKey = 'expired-device-budget';
    $expiredFile = $directory . '/' . hash('sha256', $expiredKey) . '.json';
    if (!$limiter->allowStrict($expiredKey, 2, 86400)) {
        throw new RuntimeException('Expired bucket setup failed.');
    }
    $expiredState = ['start' => time() - 172800, 'count' => 2];
    file_put_contents($expiredFile, json_encode($expiredState, JSON_THROW_ON_ERROR));
    touch($expiredFile, time() - 172800);

    $limiter->cleanup(3600);
    clearstatcache();

    if (is_file($expiredFile)) {
        throw new RuntimeException('Expired bucket was not collected.');
    }
} finally {
    foreach (glob($directory . '/*.json') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($directory);
}

echo "long-window-cleanup: OK\n";
