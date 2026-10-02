<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$installer = file_get_contents($root . '/public/shared-install.php');
$backup = file_get_contents($root . '/src/Backup/DatabaseBackupService.php');
$sharedMigration = file_get_contents($root . '/src/Migration/SharedMigrationService.php');
$migrationAction = file_get_contents($root . '/public/admin/actions/migration.php');
$migrationPage = file_get_contents($root . '/public/admin/pages/migration.php');
$package = file_get_contents($root . '/tools/release/build-shared-hosting.sh');
$apache = file_get_contents($root . '/.htaccess');

foreach ([
    'public/shared-install.php' => $installer,
    'src/Backup/DatabaseBackupService.php' => $backup,
    'src/Migration/SharedMigrationService.php' => $sharedMigration,
    'public/admin/actions/migration.php' => $migrationAction,
    'public/admin/pages/migration.php' => $migrationPage,
    'tools/release/build-shared-hosting.sh' => $package,
    '.htaccess' => $apache,
] as $path => $contentValue) {
    if ($contentValue === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
}

$installerContracts = [
    'X-Frame-Options: DENY',
    'Content-Security-Policy:',
    'session.cookie_secure',
    'function databaseSizeBytes',
    'function isMuchoCoreDatabase',
    'function verifyInstalledSchema',
    'public/database',
    'public/admin',
    'storage . \'/.htaccess\'',
    '5.5.5',
    'The configured shared-hosting database does not look like a MuchoCore installation.',
    '[MuchoCore Shared Installer]',
    'Request ID:',
    "if (\$_SERVER['REQUEST_METHOD'] === 'POST' || \$autoConfig !== null)",
    'browser_finalization_return_url',
    'notify_browser_finalization',
    'A verified target backup was created before the failed step.',
];

foreach ($installerContracts as $needle) {
    if (!str_contains($installer, $needle)) {
        throw new RuntimeException('Shared installer contract missing: ' . $needle);
    }
}

if (str_contains($installer, 'HTTP_X_FORWARDED_PROTO')) {
    throw new RuntimeException(
        'Shared installer must not trust a client-controlled X-Forwarded-Proto header for HTTPS.'
    );
}

$verifyPosition = strpos($installer, 'verifyInstalledSchema($pdo)');
$adminCreatePosition = strpos($installer, '$adminStmt->execute([');
if (
    $verifyPosition === false ||
    $adminCreatePosition === false ||
    $verifyPosition < $adminCreatePosition
) {
    throw new RuntimeException(
        'Final schema verification must run after administrator bootstrap.'
    );
}

$backupContracts = [
    'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
    'databaseSizeBytes',
    'disk_free_space',
    'gzip',
    'verifyChecksum',
];

foreach ($backupContracts as $needle) {
    if (!str_contains($backup, $needle)) {
        throw new RuntimeException('Database backup contract missing: ' . $needle);
    }
}

if (!str_contains($sharedMigration, 'DatabaseBackupService')
    || !str_contains($sharedMigration, 'requireTargetMaps')
    || !str_contains($sharedMigration, 'beginTransaction')
    || !str_contains($sharedMigration, 'Unable to create the shared-hosting storage directory.')
    || !str_contains($sharedMigration, "Require all denied")) {
    throw new RuntimeException(
        'Shared migration service must keep backup, target schema verification and transactional import.'
    );
}

if (!str_contains($migrationAction, 'MUCHO_DB_BACKUP_DIR')) {
    throw new RuntimeException(
        'Shared Migration Center must honor MUCHO_DB_BACKUP_DIR.'
    );
}

if (!str_contains($migrationAction, 'error_log(sprintf(')
    || !str_contains($migrationAction, '[MuchoCore Shared Migration]')
    || str_contains($migrationAction, '$_SESSION[\'migration_status\']=$e->getMessage()')
) {
    throw new RuntimeException(
        'Shared Migration Center must log raw errors server-side and avoid exposing them to the browser.'
    );
}

if (!str_contains($migrationPage, '$_SESSION[\'migration_form\']')) {
    throw new RuntimeException(
        'Shared Migration Center must preserve non-secret source fields across redirects.'
    );
}

foreach ([
    'required=(',
    '".htaccess"',
    '"public"',
    '"vendor"',
    'muchocore/public/shared-install.php',
    'muchocore/vendor/autoload.php',
    'unzip -Z1 "$OUTPUT"',
    'config/cloudsave.key',
] as $needle) {
    if (!str_contains($package, $needle)) {
        throw new RuntimeException(
            'Shared-hosting package contract missing: ' . $needle
        );
    }
}
foreach ([
    'RewriteRule ^shared-install\\.php$ public/shared-install.php [END]',
    'RewriteRule ^(.*)$ public/$1 [L]',
] as $needle) {
    if (!str_contains($apache, $needle)) {
        throw new RuntimeException(
            'Shared-hosting Apache routing contract missing: ' . $needle
        );
    }
}

echo "shared-hosting-installer-contract: OK\n";
