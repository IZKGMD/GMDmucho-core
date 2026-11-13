<?php

declare(strict_types=1);

/**
 * Pure webhook security regression; never performs an external network call.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Integration\WebhookDispatcher;

$check = static function (bool $ok, string $description): void {
    if (!$ok) {
        throw new RuntimeException('Webhook security: ' . $description);
    }
    echo 'PASS ' . $description . PHP_EOL;
};

$valid = [
    'https://hooks.example.org/events',
    'https://api.example.net:443/v1/events?source=muchocore',
];
foreach ($valid as $url) {
    $check(WebhookDispatcher::safeDestination($url), 'normal HTTPS operator endpoint allowed');
}
$invalid = [
    '', 'http://hooks.example.org/events', 'ftp://hooks.example.org/events',
    'https://localhost/events', 'https://host.local/events',
    'https://internal.test/events', 'https://127.0.0.1/events',
    'https://10.0.0.7/events', 'https://169.254.169.254/events',
    'https://user:pass@hooks.example.org/events',
    'https://hooks.example.org:8443/events',
    'https://hooks.example.org/events#fragment',
    'https://[::1]/events',
    "https://hooks.example.org/\r\nX-Test: true",
];
foreach ($invalid as $url) {
    $check(!WebhookDispatcher::safeDestination($url), 'unsafe webhook address rejected');
}
$check(WebhookDispatcher::publicAddress('8.8.8.8'), 'public IP eligible for pinned HTTPS');
foreach (['127.0.0.1', '10.20.1.1', '169.254.169.254', '::1', 'not-an-ip'] as $ip) {
    $check(!WebhookDispatcher::publicAddress($ip), 'non-public address rejected');
}
$check(WebhookDispatcher::safeHeaders('level.uploaded', 'trace-123'),
    'normal queued event and trace identifier accepted');
$check(WebhookDispatcher::safeHeaders('account.registered', ''),
    'empty optional trace identifier accepted');
foreach ([
    ["level.uploaded\r\nX-Injected: yes", 'trace-123'],
    ["\n", 'trace-123'],
    ['level.uploaded', "trace-123\r\nX-Injected: yes"],
    ['level.uploaded', str_repeat('a', 129)],
    [str_repeat('a', 97), 'trace'],
] as [$event, $requestId]) {
    $check(!WebhookDispatcher::safeHeaders($event, $requestId),
        'invalid queued event headers rejected');
}

$source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/Integration/WebhookDispatcher.php');
$check(
    str_contains($source, 'CURLOPT_RESOLVE') &&
    str_contains($source, 'CURLOPT_NOPROXY') &&
    str_contains($source, 'CURLOPT_FOLLOWLOCATION => false') &&
    str_contains($source, 'CURLOPT_WRITEFUNCTION') &&
    str_contains($source, 'MAX_PAYLOAD_BYTES') &&
    str_contains($source, 'MAX_RESPONSE_BYTES') &&
    !str_contains($source, 'file_get_contents($url'),
    'transport pins DNS, blocks redirects and bounds request and response sizes'
);
echo "MUCHOCORE_WEBHOOK_SECURITY_OK\n";
