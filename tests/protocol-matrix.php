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

$versions = [
    [19, '1.9'],
    [20, '2.0'],
    [21, '2.1'],
    [22, '2.2'],
];

foreach ($versions as [$gameVersion, $expectedFamily]) {
    $version = ClientVersion::fromValues($gameVersion, 1);

    assertSameValue(
        $expectedFamily,
        $version->family(),
        "family {$expectedFamily}"
    );
}

$router = new Router();

$aliases = [
    '/registerGJAccount.php' => '/registerGJAccount',
    '/loginGJAccount19.php' => '/loginGJAccount',
    '/loginGJAccount20.php' => '/loginGJAccount',
    '/loginGJAccount21.php' => '/loginGJAccount',
    '/loginGJAccount22.php' => '/loginGJAccount',
    '/getGJLevels.php' => '/getGJLevels21',
    '/getGJLevels19.php' => '/getGJLevels21',
    '/getGJLevels20.php' => '/getGJLevels21',
    '/getGJLevels22.php' => '/getGJLevels21',
    '/uploadGJLevel19.php' => '/uploadGJLevel21',
    '/uploadGJLevel20.php' => '/uploadGJLevel21',
    '/downloadGJLevel19.php' => '/downloadGJLevel21',
    '/downloadGJLevel20.php' => '/downloadGJLevel21',
    '/getGJUserInfo19.php' => '/getGJUserInfo20',
    '/getGJUserInfo21.php' => '/getGJUserInfo20',
    '/getGJUserInfo22.php' => '/getGJUserInfo20',
    '/getGJScores19.php' => '/getGJScores20',
    '/getGJScores21.php' => '/getGJScores20',
    '/getGJScores22.php' => '/getGJScores20',
    '/getGJComments19.php' => '/getGJComments21',
    '/getGJComments20.php' => '/getGJComments21',
];

foreach ($aliases as $input => $expected) {
    assertSameValue(
        strtolower($expected),
        $router->normalizePath($input),
        "compatibility alias {$input}"
    );
}

$credentials = [
    [19, false],
    [20, false],
    [21, false],
    [22, true],
];

foreach ([37, 40, 41, 42, 47, 48] as $binaryVersion) {
    $request = new Request(
        'POST',
        '/loginGJAccount22.php',
        [],
        [
            'gameVersion' => '22',
            'binaryVersion' => (string)$binaryVersion,
            'gjp' => 'legacy',
            'gjp2' => 'modern',
        ],
        []
    );

    assertSameValue(
        '2.2',
        $request->clientVersion()->family(),
        "2.2 family binary {$binaryVersion}"
    );

    assertSameValue(
        'modern',
        $request->gdCredential(),
        "2.2 GJP2 binary {$binaryVersion}"
    );
}

$legacyBoundary = new ClientVersion(20, 27);
assertSameValue('2.0', $legacyBoundary->family(), '2.0 binary 27 boundary');

$modernBoundary = new ClientVersion(20, 28);
assertSameValue('2.1', $modernBoundary->family(), '2.1 effective binary 28 boundary');

foreach ($credentials as [$gameVersion, $usesGjp2]) {
    $request = new Request(
        'POST',
        '/loginGJAccount.php',
        [],
        [
            'gameVersion' => (string)$gameVersion,
            'gjp' => 'legacy',
            'gjp2' => 'modern',
        ],
        []
    );

    assertSameValue(
        $usesGjp2 ? 'modern' : 'legacy',
        $request->gdCredential(),
        "credential selection {$gameVersion}"
    );
}

echo "MUCHOCORE_PROTOCOL_MATRIX_OK\n";
