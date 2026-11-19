<?php
declare(strict_types=1);

// Deployment readiness only. Full endpoint / real-client certification is a
// separate, deliberately stronger gate (v110-endpoint-gate.php).
$root = dirname(__DIR__, 2);
$issues = [];
$version = trim((string) file_get_contents($root . '/VERSION'));
if ($version !== '1.1.1') {
    $issues[] = 'This release gate is only authorized for version 1.1.1.';
}
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
if (!is_array($composer) || ($composer['require']['php'] ?? '') !== '^8.2') {
    $issues[] = 'Expected PHP 8.2+ production runtime is missing.';
}
$required = [
    'install.sh', 'install-remote.sh', 'update.sh',
    'public/install.php', 'public/shared-install.php', 'public/ftp-install.php',
    'src/Core/Application.php', 'database/migrations',
    'tools/release/build-shared-hosting.sh', 'docs/SHARED_HOSTING.md',
    'tests/integration/php8-first-run-http.py',
    'tests/integration/gd2209-exe-request-emulator.py',
];
foreach ($required as $path) {
    if (!file_exists($root . '/' . $path)) {
        $issues[] = 'Missing deployment input: ' . $path;
    }
}
if ($issues !== []) {
    foreach ($issues as $issue) {
        fwrite(STDERR, "NOT READY: {$issue}\n");
    }
    exit(1);
}
echo "MUCHOCORE_V111_PHP8_DEPLOYMENT_INPUTS_OK\n";
