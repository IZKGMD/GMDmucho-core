<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function assertExport(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$files = [
    'src/Deployment/GdpsExportService.php',
    'public/admin/pages/export.php',
    'public/admin/actions/export.php',
    'public/admin/index.php',
    'public/admin/config/pages.php',
    'public/admin/actions/map.php',
    'src/Admin/AdminRbac.php',
];

foreach ($files as $relative) {
    assertExport(
        is_file($root . '/' . $relative),
        'Missing export integration file: ' . $relative
    );
}

$service = (string)file_get_contents($root . '/src/Deployment/GdpsExportService.php');
$page = (string)file_get_contents($root . '/public/admin/pages/export.php');
$action = (string)file_get_contents($root . '/public/admin/actions/export.php');
$index = (string)file_get_contents($root . '/public/admin/index.php');

assertExport(str_contains($service, 'format'] = 'muchocore-gdps-export-v1'), 'Export format marker is missing.');
assertExport(str_contains($service, 'DatabaseBackupService'), 'Export must use the verified database backup service.');
assertExport(str_contains($service, '.env.example'), 'Export must include only the safe environment template.');
assertExport(str_contains($service, '.secrets/'), 'Export documentation must explicitly exclude runtime secrets.');
assertExport(str_contains($service, 'security_note'), 'Export must record its secret-exclusion policy.');
assertExport(!str_contains($service, "addFile(
                    $this->rootDir . '/.env'"), 'Export must not add the live .env.');
assertExport(str_contains($page, 'Create export package'), 'Export page action is missing.');
assertExport(str_contains($action, "requirePermission('backups.export')"), 'Export action is not permission protected.');
assertExport(str_contains($index, 'GDPS EXPORT DOWNLOAD'), 'Secure export download route is missing.');
assertExport(str_contains($index, 'muchocore-gdps-export_[0-9]{8}_[0-9]{6}\\.zip'), 'Export filename validation is missing.');

echo "gdps-export-contract: OK\n";
