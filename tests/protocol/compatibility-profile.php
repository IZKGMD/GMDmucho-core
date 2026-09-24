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

$all = new CompatibilityProfile([19, 20, 21, 22]);
assertProfile(true, $all->isAll(), 'all profile detected');

foreach ([19, 20, 21, 22] as $version) {
    assertProfile(
        true,
        $all->allows(ClientVersion::fromValues($version, 0)),
        "all profile allows GD {$version}"
    );
}

$legacy = new CompatibilityProfile([19]);
assertProfile(false, $legacy->isAll(), 'legacy profile is not all');
assertProfile(true, $legacy->allows(ClientVersion::fromValues(19, 21)), 'legacy profile allows GD 1.9');
assertProfile(false, $legacy->allows(ClientVersion::fromValues(20, 0)), 'legacy profile blocks GD 2.0');
assertProfile(false, $legacy->allows(ClientVersion::fromValues(21, 0)), 'legacy profile blocks GD 2.1');
assertProfile(false, $legacy->allows(ClientVersion::fromValues(22, 0)), 'legacy profile blocks GD 2.2');

$modern = new CompatibilityProfile([22]);
assertProfile(true, $modern->allows(ClientVersion::fromValues(22, 0)), '2.2 profile allows GD 2.2');
assertProfile(false, $modern->allows(ClientVersion::fromValues(21, 0)), '2.2 profile blocks GD 2.1');

$unknown = ClientVersion::fromValues(0, 0);
assertProfile(true, $legacy->allows($unknown), 'unknown version remains allowed');

$_ENV['MUCHO_GD_VERSIONS'] = '19,22';
$custom = CompatibilityProfile::fromEnvironment();
assertProfile(false, $custom->isAll(), 'custom environment profile');
assertProfile(true, $custom->allows(ClientVersion::fromValues(19, 0)), 'custom profile allows 1.9');
assertProfile(false, $custom->allows(ClientVersion::fromValues(20, 0)), 'custom profile blocks 2.0');
assertProfile(true, $custom->allows(ClientVersion::fromValues(22, 0)), 'custom profile allows 2.2');
assertProfile(
    '19,22',
    $custom->envValue(),
    'custom profile env serialization'
);

echo "MUCHOCORE_COMPATIBILITY_PROFILE_OK\n";
