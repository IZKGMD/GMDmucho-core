<?php

declare(strict_types=1);

function assertSessionContract(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$dashboard = (string)file_get_contents(__DIR__ . '/../../public/dashboard/index.php');
$admin = (string)file_get_contents(__DIR__ . '/../../public/admin/index.php');

assertSessionContract(
    str_contains($dashboard, "ini_set('session.cookie_lifetime', '604800')") &&
    str_contains($dashboard, "ini_set('session.gc_maxlifetime', '604800')"),
    'player session uses seven-day lifetime'
);

assertSessionContract(
    str_contains($admin, "ini_set('session.cookie_lifetime','604800')") &&
    str_contains($admin, "ini_set('session.gc_maxlifetime','604800')"),
    'admin session uses seven-day cookie lifetime'
);

assertSessionContract(
    str_contains($admin, 'const MUCHO_ADMIN_IDLE_TIMEOUT = 604800;') &&
    str_contains($admin, 'const MUCHO_ADMIN_MAX_SESSION = 2592000;'),
    'admin session has seven-day idle and thirty-day absolute limits'
);

echo "MUCHOCORE_SESSION_CONTRACT_OK\n";
