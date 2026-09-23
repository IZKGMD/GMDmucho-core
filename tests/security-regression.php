<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MuchoCore\Security\ClientIp;
use MuchoCore\Security\RateLimiter;

$root = dirname(__DIR__);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }

    echo "[PASS] {$message}\n";
}

$spoofed = [
    'REMOTE_ADDR' => '203.0.113.10',
    'HTTP_CF_CONNECTING_IP' => '198.51.100.20',
    'HTTP_X_FORWARDED_FOR' => '198.51.100.21',
    'HTTPS' => 'off',
    'HTTP_X_FORWARDED_PROTO' => 'https',
];

putenv('MUCHO_TRUSTED_PROXIES=');
$_ENV['MUCHO_TRUSTED_PROXIES'] = '';

check(
    ClientIp::resolve($spoofed) === '203.0.113.10',
    'untrusted forwarding headers cannot spoof client IP'
);

check(
    ClientIp::isHttps($spoofed) === false,
    'untrusted X-Forwarded-Proto cannot enable secure mode'
);

putenv('MUCHO_TRUSTED_PROXIES=203.0.113.0/24');
$_ENV['MUCHO_TRUSTED_PROXIES'] = '203.0.113.0/24';

check(
    ClientIp::resolve($spoofed) === '198.51.100.20',
    'trusted proxy may provide the Cloudflare client IP'
);

check(
    ClientIp::isHttps($spoofed) === true,
    'trusted proxy may provide the forwarded HTTPS state'
);

$tmp = sys_get_temp_dir() . '/muchocore-rate-test-' . bin2hex(random_bytes(6));
$limiter = new RateLimiter($tmp, false);

check(
    $limiter->allow('demo', 2, 60) === true,
    'rate limiter allows the first request'
);
check(
    $limiter->allow('demo', 2, 60) === true,
    'rate limiter allows the second request'
);
check(
    $limiter->allow('demo', 2, 60) === false,
    'rate limiter blocks requests above the limit'
);

$v2 = file_get_contents($root . '/public/api/v2/security.php');
check(is_string($v2), 'API v2 security module is readable');
check(
    str_contains($v2, 'ClientIp::resolve') &&
    !str_contains($v2, 'HTTP_CF_CONNECTING_IP'),
    'API v2 does not trust spoofable Cloudflare headers directly'
);

$failClosed = new RateLimiter('/proc/muchocore-unwritable-' . bin2hex(random_bytes(4)), false);
check(
    $failClosed->allow('demo', 1, 60) === false,
    'rate limiter fails closed when its storage cannot be opened'
);

exec('rm -rf ' . escapeshellarg($tmp));

echo "[OK] Security regression checks passed.\n";
