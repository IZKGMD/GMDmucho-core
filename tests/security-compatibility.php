<?php

declare(strict_types=1);

/* Copyright (C) 2026 IZK */

require dirname(__DIR__) . '/src/Security/ClientIp.php';

use MuchoCore\Security\ClientIp;

function expectIp(string $expected, string $actual, string $name): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $name . ': expected ' . $expected . ', got ' . $actual
        );
    }

    echo "PASS {$name}\n";
}

expectIp(
    '203.0.113.10',
    ClientIp::detect([
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_CF_CONNECTING_IP' => '198.51.100.20',
        'MUCHO_TRUST_PROXY_HEADERS' => '1',
    ]),
    'public peer cannot spoof forwarded IP'
);

expectIp(
    '198.51.100.20',
    ClientIp::detect([
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_CF_CONNECTING_IP' => '198.51.100.20',
        'MUCHO_TRUST_PROXY_HEADERS' => '1',
    ]),
    'trusted private proxy may provide client IP'
);

expectIp(
    '10.0.0.5',
    ClientIp::detect([
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_CF_CONNECTING_IP' => '198.51.100.20',
        'MUCHO_TRUST_PROXY_HEADERS' => '0',
    ]),
    'proxy headers stay disabled by default'
);

echo "SECURITY_COMPATIBILITY_OK\n";
