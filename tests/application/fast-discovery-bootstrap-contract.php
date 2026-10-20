<?php

declare(strict_types=1);

// Optional fast discovery must preserve handlers, client aliases,
// precheck requirement, authentication boundaries and full-route fallback.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Core\Application;
use MuchoCore\Core\FastDiscoveryRoutes;
use MuchoCore\Http\Request;
use MuchoCore\Routing\Router;

function fastDiscoveryAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('pdo_sqlite is required');
}

$pdo = new PDO('sqlite::memory:');
$routesFor = static function (Application $app): array {
    $router = (new ReflectionProperty(Application::class, 'router'))->getValue($app);
    return (new ReflectionProperty(Router::class, 'routes'))->getValue($router);
};
$input = ['gameVersion' => '22', 'binaryVersion' => '51'];
$requestFor = static fn(string $path): Request =>
    new Request('POST', $path, [], $input, ['REMOTE_ADDR' => '127.0.0.1']);

try {
    putenv('MUCHO_FAST_DISCOVERY_BOOT=1');
    $_ENV['MUCHO_FAST_DISCOVERY_BOOT'] = '1';

    $song = $requestFor('/database/getGJSongInfo.php');
    unset($_SERVER['MUCHO_PROTECT_PRECHECKED']);
    $ordinary = new Application($pdo, $song);
    fastDiscoveryAssert(count($routesFor($ordinary)) > 100,
        'unprechecked requests always use the complete application graph');

    $_SERVER['MUCHO_PROTECT_PRECHECKED'] = '1';
    $unknown = new Application($pdo, $requestFor('/database/loginGJAccount.php'));
    fastDiscoveryAssert(count($routesFor($unknown)) > 100,
        'authentication and unlisted routes keep the full graph');

    $cases = [
        '/database/getGJSongInfo.php' => '/getgjsonginfo',
        '/database/getGJTopArtists.php' => '/getgjtopartists',
        '/database/getGJCreators.php' => '/getgjcreators',
        '/database/getGJCreators19.php' => '/getgjcreators19',
        '/database/getGJDailyLevel.php' => '/getgjdailylevel',
        '/database/getGJGauntlets21.php' => '/getgjgauntlets21',
        '/database/getGJGauntlets19.php' => '/getgjgauntlets21',
        '/database/getGJMapPacks21.php' => '/getgjmappacks21',
        '/database/getGJMapPacks19.php' => '/getgjmappacks21',
    ];

    foreach ($cases as $inputPath => $canonicalPath) {
        $request = $requestFor($inputPath);
        $lean = new Application($pdo, $request);
        $routes = $routesFor($lean);
        fastDiscoveryAssert(count($routes) === 1
            && isset($routes['ANY ' . $canonicalPath]),
            'only the canonical handler is registered for ' . $inputPath);
    }

    $leanSong = new Application($pdo, $song);
    fastDiscoveryAssert($leanSong->handle($song)->body === $ordinary->handle($song)->body,
        'song input validation and GD wire error remain unchanged');

    fastDiscoveryAssert(!FastDiscoveryRoutes::supports('/logingjaccount'),
        'login can never use discovery fast boot');

    $standalone = new Application($pdo);
    fastDiscoveryAssert(count($routesFor($standalone)) > 100,
        'direct standalone Application always uses full bootstrap');
} finally {
    unset($_SERVER['MUCHO_PROTECT_PRECHECKED'], $_ENV['MUCHO_FAST_DISCOVERY_BOOT']);
    putenv('MUCHO_FAST_DISCOVERY_BOOT');
}

echo "MUCHOCORE_FAST_DISCOVERY_BOOT_OK\n";
