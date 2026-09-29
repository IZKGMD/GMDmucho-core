<?php
declare(strict_types=1);

$compose = file_get_contents(__DIR__ . '/../../docker-compose.yml');
$entrypoint = file_get_contents(__DIR__ . '/../../docker/app-entrypoint.sh');

if ($compose === false || $entrypoint === false) {
    fwrite(STDERR, "Unable to read worker runtime files.\n");
    exit(1);
}

$worker = preg_match('/^  worker:\n(?P<body>.*?)(?=^  caddy:)/ms', $compose, $m) ? $m['body'] : '';
$app = preg_match('/^  app:\n(?P<body>.*?)(?=^  # First hosted)/ms', $compose, $m) ? $m['body'] : '';
$testGdps = preg_match('/^  testgdps-app:\n(?P<body>.*?)(?=^\n\n  worker:)/ms', $compose, $m) ? $m['body'] : '';

$checks = [
    'worker has skip-admin-bootstrap flag' => str_contains($worker, 'MUCHO_SKIP_ADMIN_BOOTSTRAP: "1"'),
    'worker does not mount admin password secret' => !str_contains($worker, 'MUCHO_ADMIN_PASSWORD_FILE') && !preg_match('/^      - admin_password$/m', $worker),
    'main app still uses admin password secret' => str_contains($app, 'MUCHO_ADMIN_PASSWORD_FILE: /run/secrets/admin_password') && preg_match('/^      - admin_password$/m', $app),
    'test GDPS app still uses admin password secret' => str_contains($testGdps, 'MUCHO_ADMIN_PASSWORD_FILE: /run/secrets/testgdps_admin_password') && preg_match('/^      - testgdps_admin_password$/m', $testGdps),
    'entrypoint supports worker skip flag' => str_contains($entrypoint, 'MUCHO_SKIP_ADMIN_BOOTSTRAP'),
    'entrypoint requires admin secret for admin-bearing services' => str_contains($entrypoint, 'admin password secret is required for admin-bearing services'),
    'entrypoint only bootstraps admin when enabled' => str_contains($entrypoint, 'if [[ "$ADMIN_BOOTSTRAP_ENABLED" == "1" ]]; then'),
];

foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}

echo "worker-entrypoint-contract: OK\n";
