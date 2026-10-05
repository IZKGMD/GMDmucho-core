<?php

declare(strict_types=1);

use MuchoCore\Deployment\GdpsExportService;

if ($action !== 'gdps-export') {
    throw new RuntimeException('Invalid export action.');
}

try {
    requirePermission('backups.export');
    checkCsrf();

    $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 3);
    $backupDir = defined('BACKUP_DIR')
        ? BACKUP_DIR
        : $rootDir . '/storage/backups/admin-v2';

    $result = (new GdpsExportService(
        $db,
        $rootDir,
        rtrim($backupDir, '/\\') . '/exports'
    ))->create();

    audit($db, 'gdps.export.create', basename((string)$result['file']), [
        'size' => (int)$result['size'],
        'sha256' => (string)$result['sha256'],
    ]);

    $_SESSION['gdps_export_result'] = [
        'file' => basename((string)$result['file']),
        'sha256_file' => basename((string)$result['sha256_file']),
        'size' => (int)$result['size'],
    ];

    flash('GDPS export package created.');
} catch (Throwable $e) {
    flash('Export failed: ' . $e->getMessage(), 'error');
}

header('Location:/admin/?page=export');
exit;
