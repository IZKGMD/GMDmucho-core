<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Http/ClientIp.php';

use MuchoCore\Http\ClientIp;

function assertSameValue(mixed $expected, mixed $actual, string $name): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            sprintf(
                "FAIL %s: expected %s, got %s\n",
                $name,
                var_export($expected, true),
                var_export($actual, true)
            )
        );
        exit(1);
    }

    echo "PASS {$name}\n";
}

assertSameValue(
    '8.8.8.8',
    ClientIp::resolve([
        'REMOTE_ADDR' => '8.8.8.8',
        'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
    ]),
    'public peer ignores spoofable proxy headers'
);

assertSameValue(
    '1.2.3.4',
    ClientIp::resolve([
        'REMOTE_ADDR' => '172.20.0.2',
        'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
    ]),
    'private proxy trusts Cloudflare client IP'
);

assertSameValue(
    '198.51.100.25',
    ClientIp::resolve([
        'REMOTE_ADDR' => '172.20.0.2',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.25, 172.20.0.1',
    ]),
    'private proxy falls back to forwarded client IP'
);

assertSameValue(
    '172.20.0.2',
    ClientIp::resolve([
        'REMOTE_ADDR' => '172.20.0.2',
    ]),
    'private peer without forwarding headers'
);

echo "MUCHOCORE_CLIENT_IP_OK\n";
