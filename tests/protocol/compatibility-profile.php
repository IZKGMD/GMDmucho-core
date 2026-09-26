<?php

declare(strict_types=1);

require __DIR__ . '/../../src/Compatibility/ClientVersion.php';
require __DIR__ . '/../../src/Compatibility/CompatibilityProfile.php';

use MuchoCore\Compatibility\ClientVersion;
use MuchoCore\Compatibility\CompatibilityProfile;

function assertProfile(mixed $expected, mixed $actual, string $name): void
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

$all = new CompatibilityProfile([1, 11, 19, 20, 21, 22]);
assertProfile(true, $all->isAll(), 'all profile detected');

foreach ([1, 11, 19, 20, 21, 22] as $version) {
    assertProfile(
        true,
        $all->allows(ClientVersion::fromValues($version, 0)),
        "all profile allows GD {$version}"
    );
}

$legacy = new CompatibilityProfile([11]);
assertProfile('1.1', ClientVersion::fromValues(11, 0)->family(), 'GD 1.1 family detected');
assertProfile('1.1', ClientVersion::fromValues(11, 0)->label(), 'GD 1.1 label detected');
assertProfile(false, ClientVersion::fromValues(11, 0)->usesGjp2(), 'GD 1.1 does not use GJP2');
assertProfile(false, $legacy->isAll(), '1.1 profile is not all');
assertProfile(true, $legacy->allows(ClientVersion::fromValues(11, 0)), '1.1 profile allows GD 1.1');
assertProfile(false, $legacy->allows(ClientVersion::fromValues(1, 0)), '1.1 profile blocks GD 1.0');
assertProfile(false, $legacy->allows(ClientVersion::fromValues(19, 0)), '1.1 profile blocks GD 1.9');

$modern = new CompatibilityProfile([22]);
assertProfile(true, $modern->allows(ClientVersion::fromValues(22, 0)), '2.2 profile allows GD 2.2');
assertProfile(false, $modern->allows(ClientVersion::fromValues(21, 0)), '2.2 profile blocks GD 2.1');

$unknown = ClientVersion::fromValues(0, 0);
assertProfile(true, $legacy->allows($unknown), 'unknown version remains allowed');

$_ENV['MUCHO_GD_VERSIONS'] = '11,22';
$custom = CompatibilityProfile::fromEnvironment();
assertProfile(false, $custom->isAll(), 'custom environment profile');
assertProfile(true, $custom->allows(ClientVersion::fromValues(11, 0)), 'custom profile allows 1.1');
assertProfile(false, $custom->allows(ClientVersion::fromValues(20, 0)), 'custom profile blocks 2.0');
assertProfile(true, $custom->allows(ClientVersion::fromValues(22, 0)), 'custom profile allows 2.2');
assertProfile(
    '11,22',
    $custom->envValue(),
    'custom profile env serialization'
);

$_ENV['MUCHO_GD_VERSIONS'] = '10';
$gd10Alias = CompatibilityProfile::fromEnvironment();
assertProfile(true, $gd10Alias->allows(ClientVersion::fromValues(1, 0)), 'legacy GD 1.0 10 alias');

$_ENV['MUCHO_GD_VERSIONS'] = 'gd1.1';
$prefixed = CompatibilityProfile::fromEnvironment();
assertProfile(
    true,
    $prefixed->allows(ClientVersion::fromValues(11, 0)),
    'GD-prefixed 1.1 profile alias'
);

$_ENV['MUCHO_GD_VERSIONS'] = '1.1';
$decimal = CompatibilityProfile::fromEnvironment();
assertProfile(
    true,
    $decimal->allows(ClientVersion::fromValues(11, 0)),
    'decimal 1.1 profile alias'
);

$invalidRejected = false;
$_ENV['MUCHO_GD_VERSIONS'] = 'garbage';
try {
    CompatibilityProfile::fromEnvironment();
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
assertProfile(true, $invalidRejected, 'invalid environment profile is rejected');

echo "MUCHOCORE_COMPATIBILITY_PROFILE_OK\n";
