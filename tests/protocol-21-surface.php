<?php

declare(strict_types=1);

require __DIR__ . '/../src/Compatibility/ClientVersion.php';
require __DIR__ . '/../src/Http/Request.php';
require __DIR__ . '/../src/Routing/Router.php';

use MuchoCore\Compatibility\ClientVersion;
use MuchoCore\Http\Request;
use MuchoCore\Routing\Router;

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

$router = new Router();

$required21 = [
    '/loginGJAccount21.php',
    '/registerGJAccount21.php',
    '/getGJLevels21.php',
    '/uploadGJLevel21.php',
    '/downloadGJLevel21.php',
    '/getGJComments21.php',
    '/uploadGJComment21.php',
    '/getGJLevelScores211.php',
    '/getGJGauntlets21.php',
    '/getGJMapPacks21.php',
    '/likeGJItem21.php',
    '/likeGJItem211.php',
    '/rateGJStars211.php',
    '/rateGJDemon21.php',
    '/getGJCreators19.php',
    '/getGJDailyLevel.php',
];

foreach ($required21 as $path) {
    $normalized = $router->normalizePath($path);
    assertSameValue(
        $normalized,
        $router->normalizePath('/database' . $path),
        "database prefix normalization {$path}"
    );
}

$aliases21 = [
    '/loginGJAccount21.php' => '/loginGJAccount',
    '/getGJLevels20.php' => '/getGJLevels21',
    '/getGJLevels21.php' => '/getGJLevels21',
    '/uploadGJLevel20.php' => '/uploadGJLevel21',
    '/uploadGJLevel21.php' => '/uploadGJLevel21',
    '/downloadGJLevel20.php' => '/downloadGJLevel21',
    '/downloadGJLevel21.php' => '/downloadGJLevel21',
    '/getGJComments20.php' => '/getGJComments21',
    '/getGJComments21.php' => '/getGJComments21',
    '/getGJLevelsScores21.php' => '/getGJLevelsScores21',
    '/getGJMapPacks20.php' => '/getGJMapPacks21',
    '/getGJGauntlets20.php' => '/getGJGauntlets21',
    '/likeGJItem20.php' => '/likeGJItem21',
];

foreach ($aliases21 as $input => $expected) {
    assertSameValue(
        strtolower($expected),
        $router->normalizePath($input),
        "2.1 alias {$input}"
    );
}

$version21 = ClientVersion::fromValues(21, 35);
assertSameValue('2.1', $version21->family(), '2.1 family binary 35');
assertSameValue(false, $version21->usesGjp2(), '2.1 uses legacy GJP');

$request21 = new Request(
    'POST',
    '/loginGJAccount21.php',
    [],
    [
        'gameVersion' => '21',
        'binaryVersion' => '35',
        'gjp' => 'legacy-credential',
        'gjp2' => 'modern-credential',
    ],
    []
);

assertSameValue(
    'legacy-credential',
    $request21->gdCredential(),
    '2.1 credential selection'
);

$request20Legacy = new Request(
    'POST',
    '/loginGJAccount20.php',
    [],
    [
        'gameVersion' => '20',
        'binaryVersion' => '27',
        'gjp' => 'legacy-credential',
        'gjp2' => 'modern-credential',
    ],
    []
);

assertSameValue(
    '2.0',
    $request20Legacy->clientVersion()->family(),
    '2.0 binary 27 remains 2.0'
);

assertSameValue(
    'legacy-credential',
    $request20Legacy->gdCredential(),
    '2.0 credential selection'
);

$request20Modern = new Request(
    'POST',
    '/loginGJAccount20.php',
    [],
    [
        'gameVersion' => '20',
        'binaryVersion' => '28',
        'gjp' => 'legacy-credential',
        'gjp2' => 'modern-credential',
    ],
    []
);

assertSameValue(
    '2.1',
    $request20Modern->clientVersion()->family(),
    '2.0 binary 28 upgrades to 2.1 protocol family'
);

assertSameValue(
    'legacy-credential',
    $request20Modern->gdCredential(),
    '2.1 effective protocol still uses legacy GJP'
);

echo "MUCHOCORE_PROTOCOL_21_SURFACE_OK\n";
