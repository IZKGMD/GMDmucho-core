<?php

declare(strict_types=1);

/* Copyright (C) 2026 IZK */

use MuchoCore\Compatibility\ClientVersion;
use MuchoCore\Http\Request;

require __DIR__ . '/../src/Compatibility/ClientVersion.php';
require __DIR__ . '/../src/Http/Request.php';

function expectValue(mixed $expected, mixed $actual, string $name): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            "FAIL {$name}: expected " .
            var_export($expected, true) .
            " got " .
            var_export($actual, true) .
            "\n"
        );
        exit(1);
    }

    echo "PASS {$name}\n";
}

$legacyEndpoints = [
    '/database/getGJLevels.php',
    '/database/downloadGJLevel.php',
    '/database/uploadGJLevel.php',
    '/database/getGJComments.php',
    '/database/uploadGJComment.php',
    '/database/getGJScores.php',
    '/database/updateGJUserScore.php',
    '/database/likeGJItem.php',
];

foreach ($legacyEndpoints as $path) {
    $request = new Request('POST', $path, [], [], []);
    expectValue(
        '1.x',
        $request->clientVersion()->family(),
        'unversioned legacy endpoint ' . $path
    );
}

$modern = new Request(
    'POST',
    '/database/getGJLevels21.php',
    [],
    [],
    []
);

expectValue(
    '2.1',
    $modern->clientVersion()->family(),
    'versioned endpoint without gameVersion'
);

$legacy = new Request(
    'POST',
    '/database/downloadGJLevel19.php',
    [],
    [],
    []
);

expectValue(
    '1.9',
    $legacy->clientVersion()->family(),
    '1.9 endpoint inference'
);

echo "LEGACY_ENDPOINT_COMPATIBILITY_OK\n";
