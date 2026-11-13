<?php

declare(strict_types=1);

/**
 * MuchoProtect defensive regression: no network, database or production writes.
 */
require_once dirname(__DIR__, 2) . '/src/Core/Environment.php';
require_once dirname(__DIR__, 2) . '/src/Http/ClientIp.php';
require_once dirname(__DIR__, 2) . '/src/Compatibility/ClientVersion.php';
require_once dirname(__DIR__, 2) . '/src/Http/Request.php';
require_once dirname(__DIR__, 2) . '/src/Security/RateLimitBackend.php';
require_once dirname(__DIR__, 2) . '/src/Security/PenaltyStoreBackend.php';
require_once dirname(__DIR__, 2) . '/src/Security/RateLimiter.php';
require_once dirname(__DIR__, 2) . '/src/Security/AbusePenaltyStore.php';
require_once dirname(__DIR__, 2) . '/src/Security/MuchoProtect.php';

use MuchoCore\Http\Request;
use MuchoCore\Security\AbusePenaltyStore;
use MuchoCore\Security\MuchoProtect;
use MuchoCore\Security\RateLimiter;

function protectHardeningCheck(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('MuchoProtect hardening regression: ' . $description);
    }
    echo 'PASS ' . $description . PHP_EOL;
}

$root = sys_get_temp_dir() . '/muchoprotect-harden-' . bin2hex(random_bytes(8));
$limitDir = $root . '/limits';
$auditDir = $root . '/audit';
mkdir($limitDir, 0700, true);
mkdir($auditDir, 0700, true);

putenv('MUCHO_PROTECT=1');
$_ENV['MUCHO_PROTECT'] = '1';
putenv('MUCHO_PROTECT_AUDIT_DIR=' . $auditDir);
$_ENV['MUCHO_PROTECT_AUDIT_DIR'] = $auditDir;

try {
    $limiter = new RateLimiter($limitDir);
    $key = 'corrupted-on-disk-rate-counter';
    $bucket = $limitDir . '/' . hash('sha256', $key) . '.json';
    $cases = ['{broken', '{"start":"not-a-timestamp","count":4}',
        '{"start":10,"count":-4}', '{"start":10,"count":9223372036854775807}'];

    foreach ($cases as $invalid) {
        file_put_contents($bucket, $invalid);
        protectHardeningCheck(!$limiter->allowStrict($key, 2, 60),
            'malformed counter fails closed instead of resetting its quota');
        protectHardeningCheck(file_get_contents($bucket) === $invalid,
            'corrupt counter remains untouched for investigation');
    }
    unlink($bucket);
    protectHardeningCheck($limiter->allowStrict($key, 2, 60),
        'fresh counter starts normally');
    protectHardeningCheck($limiter->allowStrict($key, 2, 60),
        'valid second request is allowed');
    protectHardeningCheck(!$limiter->allowStrict($key, 2, 60),
        'valid quota is enforced on the next request');

    // Several supported legacy front controllers can compose their route
    // prefixes. All aliases should consume the exact same endpoint budget.
    $protect = new MuchoProtect(
        new RateLimiter($root . '/game-limits'),
        new AbusePenaltyStore($root . '/penalties')
    );
    $variants = [
        '/uploadGJComment21.php',
        '/api/uploadGJComment21.php',
        '/api/database/uploadGJComment21.php',
        '/accounts/api/uploadGJComment21.php',
    ];
    for ($i = 0; $i < 8; $i++) {
        $path = $variants[$i % count($variants)];
        $request = new Request('POST', $path, [], [],
            ['REMOTE_ADDR' => '192.0.2.168']);
        $result = $protect->inspect($request, $path);
        protectHardeningCheck($result['decision'] === 'allow',
            'legacy route aliases share a normal eight-request burst');
    }

    // Seed a realistic oversized audit file to exercise a single rotation.
    $auditFile = $auditDir . '/events.ndjson';
    file_put_contents($auditFile, str_repeat("{\"old\":true}\n", 750000));
    clearstatcache(true, $auditFile);
    protectHardeningCheck(filesize($auditFile) > 8 * 1024 * 1024,
        'oversized audit fixture exceeds storage ceiling');

    $path = '/api/database/uploadGJComment21.php';
    $blocked = $protect->inspect(
        new Request('POST', $path, [], [], ['REMOTE_ADDR' => '192.0.2.168']),
        $path
    );
    protectHardeningCheck($blocked['decision'] === 'block'
        && $blocked['reason'] === 'burst_limit',
        'nested legacy route cannot evade endpoint-specific rate limit');
    clearstatcache(true, $auditFile);
    protectHardeningCheck(filesize($auditFile) < 8 * 1024 * 1024,
        'audit log retains its bounded size after rotation');
    $contents = (string)file_get_contents($auditFile);
    $records = array_filter(explode("\n", trim($contents)), 'strlen');
    $last = json_decode((string)end($records), true);
    protectHardeningCheck(is_array($last)
        && ($last['reason'] ?? '') === 'burst_limit'
        && ($last['endpoint'] ?? '') === '/uploadgjcomment21',
        'audit retains current denial with canonical endpoint');
    protectHardeningCheck((fileperms($auditFile) & 0777) === 0600,
        'audit log is private to the PHP user');

    echo "MUCHOPROTECT_HARDENING_PASS\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($root);
}
