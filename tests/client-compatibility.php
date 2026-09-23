<?php

declare(strict_types=1);

require __DIR__ . '/../src/Compatibility/ClientVersion.php';
require __DIR__ . '/../src/Http/Request.php';

use MuchoCore\Compatibility\ClientVersion;
use MuchoCore\Http\Request;

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

$v19 = ClientVersion::fromValues(19, 25);
assertSameValue('1.9', $v19->family(), 'GD 1.9 family');
assertSameValue('1.9/25', $v19->label(), 'GD 1.9 binary label');
assertSameValue(false, $v19->usesGjp2(), 'GD 1.9 uses legacy credential');

$v20 = ClientVersion::fromValues(20, 27);
assertSameValue('2.0', $v20->family(), 'GD 2.0 family');

$v20World = ClientVersion::fromValues(20, 28);
assertSameValue('2.1', $v20World->family(), 'GD 2.0 binary >27 uses 2.1 protocol');
assertSameValue(21, $v20World->effectiveGameVersion(), 'GD 2.0 binary >27 effective version');

$v21 = ClientVersion::fromValues(21, 35);
assertSameValue('2.1', $v21->family(), 'GD 2.1 family');
assertSameValue(false, $v21->usesGjp2(), 'GD 2.1 uses legacy credential');

$v22 = ClientVersion::fromValues(22, 42);
assertSameValue('2.2', $v22->family(), 'GD 2.2 family');
assertSameValue(true, $v22->usesGjp2(), 'GD 2.2 prefers GJP2');

$unknown = ClientVersion::fromValues(0, 0);
assertSameValue('unknown', $unknown->family(), 'Unknown client family');
assertSameValue(false, $unknown->isKnown(), 'Unknown client detection');

$legacyRequest = new Request(
    'POST',
    '/getGJLevels21.php',
    [],
    [
        'gameVersion' => '21',
        'binaryVersion' => '35',
        'gjp' => 'legacy-credential',
        'gjp2' => 'new-credential',
    ],
    []
);

assertSameValue(
    'legacy-credential',
    $legacyRequest->gdCredential(),
    '2.1 prefers GJP'
);

$modernRequest = new Request(
    'POST',
    '/downloadGJLevel22.php',
    [],
    [
        'gameVersion' => '22',
        'binaryVersion' => '42',
        'gjp' => 'legacy-credential',
        'gjp2' => 'new-credential',
    ],
    []
);

assertSameValue(
    'new-credential',
    $modernRequest->gdCredential(),
    '2.2 prefers GJP2'
);

$unknownRequest = new Request(
    'POST',
    '/getGJLevels21.php',
    [],
    [
        'gjp' => 'legacy-credential',
        'gjp2' => 'new-credential',
    ],
    []
);

assertSameValue(
    'legacy-credential',
    $unknownRequest->gdCredential(),
    'Unknown client falls back to GJP'
);

echo "MUCHOCORE_CLIENT_COMPATIBILITY_OK\n";
