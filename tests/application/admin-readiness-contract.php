<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bin/mucho-admin-readiness.php';

$cases = [
    [0, 0, false, 'pending'],
    [0, 0, true, 'sealed_no_owner'],
    [2, 0, true, 'owner_missing'],
    [1, 1, true, 'ready'],
    [3, 1, false, 'ready'],
];

foreach ($cases as [$admins, $owners, $sealed, $expected]) {
    $actual = muchoAdminReadinessState($admins, $owners, $sealed);
    if ($actual !== $expected) {
        throw new RuntimeException("Admin readiness mismatch: expected $expected, got $actual");
    }
}

$root = dirname(__DIR__, 2);
$cli = file_get_contents($root . '/bin/mucho');
$probe = file_get_contents($root . '/bin/mucho-admin-readiness.php');
if (!is_string($cli) || !is_string($probe)) {
    throw new RuntimeException('Cannot read admin onboarding implementation');
}

$required = [
    'sudo mucho admin',
    'admin_cli()',
    'bin/mucho-owner-link.php',
    'bin/mucho-admin-readiness.php',
    'MUCHO_ADMIN_STATE=pending',
    'MUCHO_ADMIN_STATE=ready',
    'MUCHO_ADMIN_STATE=sealed_no_owner',
    'MUCHO_ADMIN_STATE=owner_missing',
    'getent ahostsv4',
    'server is not ready for players',
    'curl -4fsS --connect-timeout 5 --max-time 8',
];
foreach ($required as $marker) {
    if (!str_contains($cli, $marker)) {
        throw new RuntimeException('Doctor/admin contract missing marker: ' . $marker);
    }
}
if (str_contains($cli, 'curl -4ksSf --connect-timeout 5 --max-time 8 "https://$domain/health"')) {
    throw new RuntimeException('Public TLS verification was accidentally disabled');
}
if (!str_contains($probe, "if (PHP_SAPI !== 'cli')") ||
    !str_contains($probe, 'SELECT COUNT(*) FROM admin_users') ||
    !str_contains($probe, 'SELECT 1 FROM admin_initialization') ||
    !str_contains($probe, 'MUCHO_ADMIN_STATE=unavailable')) {
    throw new RuntimeException('Read-only administrator state guard changed');
}

echo "MUCHOCORE_ADMIN_READINESS_CONTRACT_OK\n";
