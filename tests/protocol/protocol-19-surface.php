<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Compatibility/ClientVersion.php';
require __DIR__ . '/../../src/Http/Request.php';
require __DIR__ . '/../../src/Routing/Router.php';

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

$version = ClientVersion::fromValues(19, 21);

assertSameValue('1.9', $version->family(), 'GD 1.9 family');
assertSameValue(false, $version->usesGjp2(), 'GD 1.9 legacy GJP');

$aliases = [
    '/loginGJAccount.php' => '/loginGJAccount',
    '/loginGJAccount19.php' => '/loginGJAccount',
    '/registerGJAccount.php' => '/registerGJAccount',
    '/registerGJAccount19.php' => '/registerGJAccount',
    '/getGJLevels.php' => '/getGJLevels21',
    '/getGJLevels19.php' => '/getGJLevels21',
    '/getGJCreators19.php' => '/getGJCreators19',
    '/uploadGJLevel19.php' => '/uploadGJLevel21',
    '/updateGJLevel.php' => '/updateGJLevel',
    '/updateGJLevel19.php' => '/updateGJLevel',
    '/updateGJLevel20.php' => '/updateGJLevel',
    '/downloadGJLevel19.php' => '/downloadGJLevel21',
    '/getGJComments19.php' => '/getGJComments21',
    '/uploadGJComment19.php' => '/uploadGJComment20',
    '/deleteGJComment19.php' => '/deleteGJComment20',
    '/deleteGJLevelUser19.php' => '/deleteGJLevelUser20',
    '/getGJUserInfo19.php' => '/getGJUserInfo20',
    '/getGJUsers19.php' => '/getGJUsers20',
    '/getGJScores19.php' => '/getGJScores20',
    '/updateGJUserScore19.php' => '/updateGJUserScore',
    '/getGJLevelScores19.php' => '/getGJLevelScores',
    '/likeGJItem19.php' => '/likeGJItem21',
    '/getGJMapPacks19.php' => '/getGJMapPacks21',
    '/getGJGauntlets19.php' => '/getGJGauntlets21',
];

$router = new Router();

foreach ($aliases as $input => $expected) {
    assertSameValue(
        strtolower($expected),
        $router->normalizePath($input),
        "1.9 compatibility alias {$input}"
    );
}

foreach (['getGJLevels21', 'uploadGJLevel21', 'downloadGJLevel21'] as $handler) {
    assertSameValue(
        strtolower('/' . $handler),
        $router->normalizePath('/' . $handler . '.php'),
        "1.9 shared handler {$handler}"
    );
}

$request = new Request(
    'POST',
    '/getGJLevels19.php',
    [],
    [
        'gameVersion' => '19',
        'binaryVersion' => '21',
        'gjp' => 'legacy-token',
        'gjp2' => 'modern-token',
    ],
    []
);

assertSameValue(
    'legacy-token',
    $request->gdCredential(),
    '1.9 credential selection'
);

assertSameValue(
    '1.9',
    $request->clientVersion()->family(),
    '1.9 request family'
);

foreach ([
    '/getGJComments19.php',
    '/uploadGJComment19.php',
    '/deleteGJComment19.php',
    '/updateGJLevel19.php',
    '/getGJComments.php',
    '/uploadGJComment.php',
    '/deleteGJComment.php',
] as $commentPath) {
    $commentRequest = new Request(
        'POST',
        $commentPath,
        [],
        [
            'accountID' => '123',
            'udid' => 'legacy-device',
        ],
        []
    );

    assertSameValue(
        '1.9',
        $commentRequest->clientVersion()->family(),
        "1.9 legacy endpoint infers legacy family {$commentPath}"
    );
}

assertSameValue(
    '/getgjcreators19',
    $router->normalizePath('/getGJCreators19.php'),
    '1.9 creator discovery route'
);

echo "MUCHOCORE_PROTOCOL_19_SURFACE_OK\n";
