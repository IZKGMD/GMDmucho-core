<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use MuchoCore\Core\Application;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Routing\Router;
use MuchoCore\Security\RateLimiter;

ob_start();

$root = dirname(__DIR__);

try {
    require_once $root . '/vendor/autoload.php';

    if (file_exists($root . '/.env')) {
        Dotenv::createImmutable($root)->safeLoad();
    }
} catch (\Throwable $e) {
    error_log('[MuchoCore Config] ' . $e->getMessage());

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
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

    $limits = [
        '/registergjaccount'    => [5, 60],
        '/logingjaccount'       => [30, 60],
        '/backupgjaccount'      => [6, 60],
        '/backupgjaccount20'    => [6, 60],
        '/syncgjaccount'        => [12, 60],
        '/syncgjaccount20'      => [12, 60],
        '/uploadgjlevel21'      => [10, 60],
        '/uploadgjlevel22'      => [10, 60],
        '/updategjleveldesc20'  => [20, 60],
        '/deletegjleveluser20'  => [10, 60],
        '/getgjlevelscores'     => [120, 60],
        '/getgjlevelscores211'  => [120, 60],
        '/getgjlevelscoresplat' => [120, 60],
        '/uploadgjcomment20'    => [30, 60],
        '/uploadgjcomment21'    => [30, 60],
        '/uploadgjacccomment20' => [20, 60],
        '/deletegjcomment20'    => [30, 60],
        '/deletegjacccomment20' => [30, 60],
        '/updategjuserscore'    => [30, 60],
        '/updategjuserscore22'  => [30, 60],
        '/getgjrewards'         => [60, 60],
        '/getgjchallenges'      => [60, 60],
        '/suggestgjstars20'     => [20, 60],
        '/rategjstars20'        => [20, 60],
        '/rategjstars211'       => [20, 60],
        '/rategjdemon21'        => [20, 60],
        '/reportgjlevel'        => [10, 60],
    ];

    if ($request->method === 'POST' && isset($limits[$path])) {
        [$limit, $window] = $limits[$path];
        $rateLimiter = new RateLimiter();

        $allowed = $rateLimiter->allow(
            $request->clientIp() . '|' . $path,
            $limit,
            $window
        );

        if (!$allowed) {
            Response::text('-1')->send();
        }
    }

    (new Application())->run();

} catch (\Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo '-1';
}
