<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Core\Environment;
use MuchoCore\Integration\WebhookDispatcher;

$check = static function (bool $condition, string $name): void {
    if (!$condition) {
        throw new RuntimeException('Webhook safety: ' . $name);
    }
};

foreach (['https://hooks.example.test/path', 'http://127.0.0.1:8123/hook',
    'https://example.test:443/receive?x=1'] as $url) {
    $check(WebhookDispatcher::acceptsUrl($url), 'valid HTTP(S) webhook accepted');
}
foreach (['', 'file:///etc/passwd', 'ftp://example.test/data',
    'gopher://example.test', 'https://a:b@example.test/x',
    'https://example.test/#secret', "https://example.test/\r\nX-Hijack: 1"] as $url) {
    $check(!WebhookDispatcher::acceptsUrl($url), 'unsafe or non-HTTP URL rejected');
}

$root = sys_get_temp_dir() . '/mc-webhook-security-' . bin2hex(random_bytes(5));
if (!mkdir($root, 0700, true)) {
    throw new RuntimeException('Cannot create local webhook fixture.');
}
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException('Cannot reserve local HTTP fixture port.');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$marker = $root . '/captured.json';
$router = $root . '/router.php';
$routerSource = <<<'PHP'
<?php
if (str_starts_with($_SERVER['REQUEST_URI'], '/redirect')) {
    header('Location: /capture', true, 302);
    exit;
}
if (str_starts_with($_SERVER['REQUEST_URI'], '/capture')) {
    file_put_contents(__DIR__ . '/captured.json', file_get_contents('php://input'));
    http_response_code(200);
    echo 'captured';
    exit;
}
http_response_code(200);
echo 'ok';
PHP;
file_put_contents($router, $routerSource);
$proc = null;
$pipes = [];
$oldEnv = getenv('MUCHO_WEBHOOK_URL');
$oldEvents = getenv('MUCHO_WEBHOOK_EVENTS');
$oldSecret = getenv('MUCHO_WEBHOOK_SECRET');
try {
    $proc = proc_open([PHP_BINARY, '-S', $address, $router],
        [0 => ['pipe', 'r'], 1 => ['file', $root . '/stdout.log', 'a'],
         2 => ['file', $root . '/stderr.log', 'a']], $pipes, $root);
    if (!is_resource($proc)) {
        throw new RuntimeException('Cannot start local webhook fixture.');
    }
    for ($n = 0; $n < 40; $n++) {
        $port = (int)substr(strrchr($address, ':'), 1);
        $ready = @fsockopen('127.0.0.1', $port, $errNo, $errStr, 0.1);
        if (is_resource($ready)) {
            fclose($ready);
            break;
        }
        usleep(50000);
    }
    putenv('MUCHO_WEBHOOK_URL=http://' . $address . '/redirect');
    putenv('MUCHO_WEBHOOK_EVENTS=*');
    putenv('MUCHO_WEBHOOK_SECRET=fixture-secret');
    $_ENV['MUCHO_WEBHOOK_URL'] = 'http://' . $address . '/redirect';
    $_ENV['MUCHO_WEBHOOK_EVENTS'] = '*';
    $_ENV['MUCHO_WEBHOOK_SECRET'] = 'fixture-secret';

    // Environment is a one-request snapshot; exercise with the values set
    // before its first read in this isolated regression process.
    $dispatcher = new WebhookDispatcher();
    $check($dispatcher->enabledFor('test.event'), 'fixture webhook is active');
    $check(!$dispatcher->dispatch('test.event', ['safe' => true]),
        'HTTP 302 must not count as successful delivery');
    $check(!is_file($marker),
        'HTTP redirect must not forward signed event body to another route');
    $check(!$dispatcher->dispatch("event\r\nX-Injected: 1", []),
        'header-break injection in event name is refused');
} finally {
    foreach (['MUCHO_WEBHOOK_URL' => $oldEnv,
        'MUCHO_WEBHOOK_EVENTS' => $oldEvents,
        'MUCHO_WEBHOOK_SECRET' => $oldSecret] as $key => $old) {
        if ($old === false) {
            putenv($key);
            unset($_ENV[$key]);
        } else {
            putenv($key . '=' . $old);
            $_ENV[$key] = $old;
        }
    }
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    foreach (glob($root . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($root);
}
echo "MUCHOCORE_WEBHOOK_TRANSPORT_SECURITY_OK\n";
