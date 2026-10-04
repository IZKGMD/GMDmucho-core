<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$module = file_get_contents($root . '/public/admin/muchops-module.php');
$action = file_get_contents($root . '/public/admin/actions/ops.php');
$map = file_get_contents($root . '/public/admin/actions/map.php');
$rbac = file_get_contents($root . '/src/Admin/AdminRbac.php');
$router = file_get_contents($root . '/public/admin/core/AdminRouter.php');
$index = file_get_contents($root . '/public/admin/index.php');
$snapshot = file_get_contents($root . '/src/Monitoring/ControlPlaneSnapshot.php');

foreach ([
    'public/admin/muchops-module.php' => $module,
    'public/admin/actions/ops.php' => $action,
    'public/admin/actions/map.php' => $map,
    'src/Admin/AdminRbac.php' => $rbac,
    'public/admin/core/AdminRouter.php' => $router,
    'public/admin/index.php' => $index,
    'src/Monitoring/ControlPlaneSnapshot.php' => $snapshot,
] as $path => $content) {
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
}

foreach ([
    'MuchoOps Control Center',
    'Service Health',
    'Control Actions',
    'Background Jobs',
    'API Performance',
    'Active Alerts',
    'Client Releases',
    'Migration Readiness',
    'auto-refresh every 15 seconds',
] as $needle) {
    if (!str_contains($module, $needle)) {
        throw new RuntimeException('MuchoOps UI contract missing: ' . $needle);
    }
}

foreach ([
    'ops-maintenance',
    'ops-registrations',
    'ops-retry-stale-jobs',
    'ops-resolve-alert',
    "requirePermission('system.manage')",
    "maintenance.flag",
    "registrations-disabled.flag",
    'retryStale(',
    'mucho_system_alerts',
    'audit(',
] as $needle) {
    if (!str_contains($action, $needle)) {
        throw new RuntimeException('MuchoOps action contract missing: ' . $needle);
    }
}

foreach ([
    "'ops-maintenance'",
    "'ops-registrations'",
    "'ops-retry-stale-jobs'",
    "'ops-resolve-alert'",
] as $needle) {
    if (!str_contains($map . $rbac, $needle)) {
        throw new RuntimeException('MuchoOps RBAC/action map contract missing: ' . $needle);
    }
}

if (!str_contains($router, "'ops'")) {
    throw new RuntimeException('MuchoOps admin route is not registered.');
}

if (!str_contains($index, "require __DIR__.'/actions/ops.php';")) {
    throw new RuntimeException('MuchoOps admin action dispatcher wiring is missing.');
}

foreach ([
    "'services' => $this->services()",
    "'database'",
    "'scheduler'",
    "'backups'",
    "'storage'",
    'disk_free_percent',
] as $needle) {
    if (!str_contains($snapshot, $needle)) {
        throw new RuntimeException('Control-plane service health contract missing: ' . $needle);
    }
}

if (str_contains($module, "window.setTimeout(refresh, 15000);") === false) {
    throw new RuntimeException('MuchoOps live refresh interval is missing.');
}

echo "MUCHOCORE_MUCHOPS_OK\n";
