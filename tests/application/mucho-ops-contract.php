<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function assertOps(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$service = $root . '/src/Monitoring/ControlPlaneSnapshot.php';
$module = $root . '/public/admin/muchops-module.php';
$router = $root . '/public/admin/core/AdminRouter.php';
$pages = $root . '/public/admin/config/pages.php';
$index = $root . '/public/admin/index.php';
$rbac = $root . '/src/Admin/AdminRbac.php';

foreach ([$service, $module, $router, $pages, $index, $rbac] as $file) {
    assertOps(is_file($file), 'Missing MuchoOps integration file: ' . $file);
}

$serviceText = file_get_contents($service);
$moduleText = file_get_contents($module);
$routerText = file_get_contents($router);
$pagesText = file_get_contents($pages);
$indexText = file_get_contents($index);
$rbacText = file_get_contents($rbac);

assertOps(str_contains($serviceText, 'final class ControlPlaneSnapshot'), 'ControlPlaneSnapshot class is missing.');
assertOps(str_contains($serviceText, 'mucho_api_metrics_minute'), 'MuchoOps must aggregate API metrics.');
assertOps(str_contains($serviceText, 'mucho_jobs'), 'MuchoOps must inspect background jobs.');
assertOps(str_contains($serviceText, 'mucho_system_alerts'), 'MuchoOps must inspect system alerts.');
assertOps(str_contains($serviceText, 'mucho_security_events'), 'MuchoOps must inspect security events.');
assertOps(str_contains($serviceText, 'mucho_backup_verifications'), 'MuchoOps must inspect backup verification state.');
assertOps(str_contains($serviceText, 'mucho_client_releases'), 'MuchoOps must inspect client releases.');
assertOps(str_contains($serviceText, 'mucho_level_search_index'), 'MuchoOps must inspect search index coverage.');
assertOps(str_contains($serviceText, 'schema_migrations'), 'MuchoOps must inspect schema migration readiness.');
assertOps(str_contains($moduleText, 'Migration readiness'), 'MuchoOps migration section is missing.');
assertOps(str_contains($moduleText, 'window.setTimeout(refresh, 15000)'), 'MuchoOps live refresh is missing.');
assertOps(str_contains($moduleText, 'MuchoOps Control Plane'), 'MuchoOps page title is missing.');
assertOps(str_contains($moduleText, 'Background Jobs'), 'MuchoOps jobs section is missing.');
assertOps(str_contains($moduleText, 'API Performance'), 'MuchoOps API section is missing.');
assertOps(str_contains($moduleText, 'Security Activity'), 'MuchoOps security section is missing.');
assertOps(str_contains($moduleText, 'Backups & Derived Data'), 'MuchoOps backup section is missing.');
assertOps(str_contains($routerText, "'ops'"), 'Admin router does not register the ops page.');
assertOps(str_contains($pagesText, "'ops'=>'MuchoOps'"), 'Admin page registry does not expose MuchoOps.');
assertOps(str_contains($rbacText, "'ops' => 'monitoring.view'"), 'MuchoOps is not protected by monitoring.view.');
assertOps(str_contains($indexText, "isset(\$_GET['ops_feed'])"), 'MuchoOps live feed is not wired in the admin front controller.');
assertOps(str_contains($indexText, "ControlPlaneSnapshot"), 'Admin front controller does not expose the MuchoOps snapshot feed.');
assertOps(str_contains($indexText, "'dashboard','ops','analytics'"), 'MuchoOps is not in the main admin navigation.');

echo "mucho-ops-contract: OK\n";
