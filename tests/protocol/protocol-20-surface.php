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

$router = new Router();

$aliases = [
    '/acceptGJFriendRequest20.php' => '/acceptGJFriendRequest20',
    '/accounts/syncGJAccount20.php' => '/syncGJAccount',
    '/blockGJUser20.php' => '/blockGJUser20',
    '/deleteGJAccComment20.php' => '/deleteGJAccComment20',
    '/deleteGJComment20.php' => '/deleteGJComment20',
    '/deleteGJFriendRequests20.php' => '/deleteGJFriendRequests20',
    '/deleteGJLevelUser20.php' => '/deleteGJLevelUser20',
    '/deleteGJMessages20.php' => '/deleteGJMessages20',
    '/downloadGJLevel20.php' => '/downloadGJLevel21',
    '/downloadGJMessage20.php' => '/downloadGJMessage20',
    '/getGJAccountComments20.php' => '/getGJAccountComments20',
    '/getGJComments20.php' => '/getGJComments21',
    '/getGJFriendRequests20.php' => '/getGJFriendRequests20',
    '/getGJLevels20.php' => '/getGJLevels21',
    '/getGJMapPacks20.php' => '/getGJMapPacks21',
    '/getGJMessages20.php' => '/getGJMessages20',
    '/getGJScores20.php' => '/getGJScores20',
    '/getGJUserInfo20.php' => '/getGJUserInfo20',
    '/getGJUserList20.php' => '/getGJUserList20',
    '/getGJUsers20.php' => '/getGJUsers20',
    '/likeGJItem20.php' => '/likeGJItem21',
    '/rateGJStars20.php' => '/rateGJStars20',
    '/readGJFriendRequest20.php' => '/readGJFriendRequest20',
    '/removeGJFriend20.php' => '/removeGJFriend20',
    '/suggestGJStars20.php' => '/suggestGJStars20',
    '/unblockGJUser20.php' => '/unblockGJUser20',
    '/updateGJAccSettings20.php' => '/updateGJAccSettings20',
    '/updateGJDesc20.php' => '/updateGJLevelDesc20',
    '/updateGJUserScore20.php' => '/updateGJUserScore',
    '/uploadFriendRequest20.php' => '/uploadFriendRequest20',
    '/uploadGJAccComment20.php' => '/uploadGJAccComment20',
    '/uploadGJComment20.php' => '/uploadGJComment20',
    '/uploadGJLevel20.php' => '/uploadGJLevel21',
    '/uploadGJMessage20.php' => '/uploadGJMessage20',
    '/getGJLevelScores20.php' => '/getGJLevelScores',
];

foreach ($aliases as $input => $expected) {
    assertSameValue(
        strtolower($expected),
        $router->normalizePath($input),
        "2.0 endpoint {$input}"
    );
}

$v20 = ClientVersion::fromValues(20, 27);
assertSameValue('2.0', $v20->family(), '2.0 binary 27 family');
assertSameValue(20, $v20->effectiveGameVersion(), '2.0 binary 27 effective version');
assertSameValue(false, $v20->usesGjp2(), '2.0 uses legacy GJP');

$v20Boundary = ClientVersion::fromValues(20, 28);
assertSameValue('2.1', $v20Boundary->family(), '2.0 binary 28 compatibility boundary');
assertSameValue(21, $v20Boundary->effectiveGameVersion(), '2.0 binary 28 effective version');

$request = new Request(
    'POST',
    '/getGJLevels20.php',
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
    'legacy-credential',
    $request->gdCredential(),
    '2.0 credential selection'
);

echo "MUCHOCORE_PROTOCOL_20_SURFACE_OK\n";
