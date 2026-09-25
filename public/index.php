<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use MuchoCore\Core\Application;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Routing\Router;
use MuchoCore\Security\MuchoProtect;

ob_start();

//
// Keep the legacy liveness endpoints independent from the database/application
// bootstrap. Installers, load balancers, and Geometry Dash clients use these
// endpoints to determine whether the HTTP service itself is alive.
//
$__muchoLivenessPath = parse_url(
    (string)($_SERVER['REQUEST_URI'] ?? '/'),
    PHP_URL_PATH
);
$__muchoLivenessPath = is_string($__muchoLivenessPath)
    ? strtolower(rtrim($__muchoLivenessPath, '/'))
    : '/';

if (
    $__muchoLivenessPath === '/health' ||
    $__muchoLivenessPath === '/checkifserveronline'
) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo '1';
    exit;
}

$root = dirname(__DIR__);

try {
    require_once $root . '/vendor/autoload.php';

    if (file_exists($root . '/.env')) {
        Dotenv::createImmutable($root)->safeLoad();
    }
} catch (\Throwable $e) {
    error_log(sprintf(
        '[MuchoCore Config] %s: %s | %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo '-1';
    exit;
}

/* MUCHO CONTROL FLAGS */
$__muchoControl = $_ENV['MUCHO_CONTROL_DIR']
    ?? getenv('MUCHO_CONTROL_DIR')
    ?: '/var/lib/muchocore-control';
$__muchoUri = strtolower((string)($_SERVER['REQUEST_URI'] ?? ''));

if (!is_dir($__muchoControl)) {
    @mkdir($__muchoControl, 0770, true);
}

if (is_file($__muchoControl . '/maintenance.flag')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo '-1';
    exit;
}

if (
    is_file($__muchoControl . '/registrations-disabled.flag') &&
    str_contains($__muchoUri, 'registergjaccount')
) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo '-1';
    exit;
}
/* END MUCHO CONTROL FLAGS */

$requestId = bin2hex(random_bytes(8));
$_SERVER['MUCHO_REQUEST_ID'] = $requestId;

try {
    $request = Request::fromGlobals();

    $router = new Router();
    $path = strtolower($router->normalizePath($request->path));

    // Apply the unified protection layer before constructing Application/DB state.
    $protection = (new MuchoProtect())->inspect(
        $request,
        $path
    );

    if ($protection['decision'] === 'block') {
        $_SERVER['MUCHO_PROTECT_PRECHECKED'] = '1';
        Response::text('-1')->send();
        exit;
    }

    $_SERVER['MUCHO_PROTECT_PRECHECKED'] = '1';

    (new Application())->run();

} catch (\Throwable $e) {
    error_log(sprintf(
        '[MuchoCore FrontController] %s %s | %s: %s | %s:%d',
        (string)($_SERVER['REQUEST_METHOD'] ?? '?'),
        (string)($_SERVER['REQUEST_URI'] ?? '?'),
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo '-1';
}
