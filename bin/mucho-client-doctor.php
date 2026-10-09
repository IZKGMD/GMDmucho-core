<?php

declare(strict_types=1);

/**
 * Inside-container MuchoClient smoke test.
 *
 * This is deliberately separate from curl/HTTP: it checks the live
 * PHP Application + configured database + plugin registry directly.
 * Use an HTTPS curl afterwards to independently verify reverse-proxy routing.
 *
 *   docker compose exec -T app php bin/mucho-client-doctor.php
 */

use Dotenv\Dotenv;
use MuchoCore\Core\Application;
use MuchoCore\Http\Request;

$root = dirname(__DIR__);

try {
    require_once $root . '/vendor/autoload.php';
    if (is_file($root . '/.env')) {
        Dotenv::createImmutable($root)->safeLoad();
    }

    echo "[MuchoClient] PHP application smoke test\n";

    $_SERVER['MUCHO_PROTECT_PRECHECKED'] = '1';
    // The real front controller has already run MuchoProtect before setting
    // this flag. Here we bypass the network limiter only for a local CLI test.

    $app = new Application();

    $manifest = $app->handle(new Request(
        'GET',
        '/muchoclient/manifest',
        [],
        [],
        ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '127.0.0.1']
    ));

    if (
        $manifest->status !== 200 ||
        !str_starts_with($manifest->contentType, 'application/json')
    ) {
        fwrite(
            STDERR,
            "[MuchoClient] FAIL: manifest response was not JSON " .
            "(status={$manifest->status}, body=" .
            substr($manifest->body, 0, 40) . ")\n"
        );
        exit(1);
    }

    $catalog = json_decode($manifest->body, true, 16, JSON_THROW_ON_ERROR);
    if (
        !is_array($catalog) ||
        ($catalog['client']['id'] ?? '') !== 'izkgmd.muchoclient' ||
        ($catalog['client']['protocol'] ?? 0) !== 1 ||
        !is_array($catalog['features'] ?? null)
    ) {
        fwrite(STDERR, "[MuchoClient] FAIL: malformed discovery manifest\n");
        exit(1);
    }

    $count = count($catalog['features']);
    echo "[MuchoClient] OK: manifest returned {$count} feature(s)\n";

    $negotiation = $app->handle(new Request(
        'POST',
        '/muchoclient/negotiate',
        [],
        ['client_version' => '0.2.1', 'protocol' => '1'],
        ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1']
    ));

    if (
        $negotiation->status !== 200 ||
        !str_starts_with($negotiation->contentType, 'application/json')
    ) {
        fwrite(STDERR, "[MuchoClient] FAIL: negotiation is not JSON\n");
        exit(1);
    }

    $handshake = json_decode(
        $negotiation->body,
        true,
        16,
        JSON_THROW_ON_ERROR
    );

    if (
        ($handshake['compatible'] ?? false) !== true ||
        ($handshake['status'] ?? '') !== 'ready' ||
        ($handshake['protocol'] ?? 0) !== 1 ||
        ($handshake['session_token'] ?? null) !== null
    ) {
        fwrite(STDERR, "[MuchoClient] FAIL: handshake rejected client\n");
        exit(1);
    }

    echo "[MuchoClient] OK: protocol v1 handshake accepted\n";
    echo "[MuchoClient] PHP and plugin bridge: READY\n";
} catch (Throwable $exception) {
    // Do not dump raw exception messages, which may contain SQL or secrets.
    fwrite(
        STDERR,
        "[MuchoClient] FAIL: " . $exception::class .
        " — consult the PHP-FPM application logs for details\n"
    );
    exit(1);
}
