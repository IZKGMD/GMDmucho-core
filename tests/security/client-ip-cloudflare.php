<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Http/ClientIp.php';

use MuchoCore\Http\ClientIp;

function assertClientIpHardening(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$cloudflarePeer = '104.16.10.20';
$realClient = '203.0.113.77';

assertClientIpHardening(
    ClientIp::resolve([
        'REMOTE_ADDR' => $cloudflarePeer,
        'HTTP_CF_CONNECTING_IP' => $realClient,
        'HTTP_X_FORWARDED_FOR' => '198.51.100.10, ' . $realClient,
    ]) === $realClient,
    'Cloudflare trusted peer resolves CF-Connecting-IP'
);

assertClientIpHardening(
    ClientIp::resolve([
        'REMOTE_ADDR' => '198.51.100.20',
        'HTTP_CF_CONNECTING_IP' => $realClient,
    ]) === '198.51.100.20',
    'untrusted public peer cannot spoof CF-Connecting-IP'
);

putenv('MUCHO_TRUSTED_PROXY_CIDRS=10.10.0.0/16');
$_ENV['MUCHO_TRUSTED_PROXY_CIDRS'] = '10.10.0.0/16';

assertClientIpHardening(
    ClientIp::resolve([
        'REMOTE_ADDR' => '10.10.2.3',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.88',
    ]) === '203.0.113.88',
    'explicit trusted proxy CIDR still resolves X-Forwarded-For'
);

putenv('MUCHO_TRUSTED_PROXY_CIDRS');
unset($_ENV['MUCHO_TRUSTED_PROXY_CIDRS']);

echo "client-ip-cloudflare: OK\n";
