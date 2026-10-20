<?php

declare(strict_types=1);

// The lean GD level-list bootstrap is explicitly opt-in. This test ensures
// ordinary callers still receive the complete route graph, protected level
// list types remain authenticated, and the hot path avoids unrelated graphs.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Core\Application;
use MuchoCore\Http\Request;
use MuchoCore\Routing\Router;

function leanAssert(bool $value, string $label): void
{
    if (!$value) {
        throw new RuntimeException('FAIL ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
}

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('pdo_sqlite is required for isolated route test');
}

$pdo = new PDO('sqlite::memory:');
$known = new Request(
    'POST',
    '/database/getGJLevels21.php',
    [],
    ['type' => '13', 'page' => '0', 'gameVersion' => '22', 'binaryVersion' => '51'],
    ['REMOTE_ADDR' => '127.0.0.1']
);
$routesFor = static function (Application $app): array {
    $router = (new ReflectionProperty(Application::class, 'router'))->getValue($app);
    if (!$router instanceof Router) {
        throw new RuntimeException('App routing missing');
    }
    return (new ReflectionProperty(Router::class, 'routes'))->getValue($router);
};

try {
    putenv('MUCHO_FAST_LEVEL_BOOT=1');
    $_ENV['MUCHO_FAST_LEVEL_BOOT'] = '1';

    unset($_SERVER['MUCHO_PROTECT_PRECHECKED']);
    $ordinary = new Application($pdo, $known);
    $ordinaryRoutes = $routesFor($ordinary);
    leanAssert(count($ordinaryRoutes) > 100,
        'without precheck full route registration remains intact');
    leanAssert(isset($ordinaryRoutes['ANY /getgjlevels21']),
        'regular application still registers canonical search');

    // The flag alone must not be enough. A front-controller inspection is
    // mandatory, exactly as on the production request path.
    $_SERVER['MUCHO_PROTECT_PRECHECKED'] = '1';
    $lean = new Application($pdo, $known);
    $leanRoutes = $routesFor($lean);
    leanAssert(count($leanRoutes) === 1
        && isset($leanRoutes['ANY /getgjlevels21']),
        'prechecked level list registers only its handler');

    foreach (['8', '13', '27'] as $type) {
        $request = new Request(
            'POST', '/database/getGJLevels21.php', [],
            ['type' => $type, 'gameVersion' => '22', 'binaryVersion' => '51'],
            ['REMOTE_ADDR' => '127.0.0.1']
        );
        $response = $lean->handle($request);
        leanAssert($response->body === '-1',
            'type ' . $type . ' still requires authentication');
    }

    // No request context -> keep the entire normal application graph.
    $direct = new Application($pdo);
    leanAssert(count($routesFor($direct)) === count($ordinaryRoutes),
        'standalone Application has complete legacy routes');
} finally {
    unset($_SERVER['MUCHO_PROTECT_PRECHECKED']);
    unset($_ENV['MUCHO_FAST_LEVEL_BOOT']);
    putenv('MUCHO_FAST_LEVEL_BOOT');
}

echo "MUCHOCORE_FAST_LEVEL_BOOT_OK\n";
