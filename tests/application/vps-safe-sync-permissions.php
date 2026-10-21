<?php

declare(strict_types=1);

// Keep the production backup-first deploy tool from checking out unreadable
// source files. This regression caused HTTP 500 for the admin client patcher
// and "WindowsClientPatcher class not found" after updating on a root-owned VPS.
$root = dirname(__DIR__, 2);
$script = file_get_contents($root . '/tools/deploy/vps-safe-sync.sh');
if (!is_string($script)) {
    throw new RuntimeException('Missing production safe-sync script.');
}
$checks = [
    'Safe source checkout umask' => preg_match('/^umask 022$/m', $script) === 1,
    'No private default checkout umask' => preg_match('/^umask 077$/m', $script) === 0,
    'Limit permissions repair to source and public' =>
        str_contains($script, '"$ROOT/src" "$ROOT/public"'),
    'Readability repair for source files' =>
        str_contains($script, '-type f -exec chmod a+r'),
    'Directory traversal repair' =>
        str_contains($script, '-type d -exec chmod a+rx'),
    'Verify as www-data not root' =>
        str_contains($script, 'exec -T -u www-data app php'),
    'Windows client source checked' =>
        str_contains($script, '/src/Client/WindowsClientPatcher.php'),
    'Android client source checked' =>
        str_contains($script, '/src/Client/AndroidClientPatcher.php'),
];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        throw new RuntimeException('Deployment source access regression: ' . $label);
    }
}
echo "vps-safe-sync-permissions: OK\\n";
