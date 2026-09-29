<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$detector = file_get_contents($root . '/src/Migration/SourceDetector.php');
$wizard = file_get_contents($root . '/bin/mucho-migrate.php');
$backupScript = file_get_contents($root . '/bin/mucho-db-backup.sh');
$phpBackup = file_get_contents($root . '/src/Backup/DatabaseBackupService.php');
$sharedMigration = file_get_contents($root . '/src/Migration/SharedMigrationService.php');
$sharedInstaller = file_get_contents($root . '/public/shared-install.php');
$adminBackup = file_get_contents($root . '/public/admin/db-backup-center-module.php');
$adminAction = file_get_contents($root . '/public/admin/actions/migration.php');
$adminActionPrevious = $adminAction; // retain the existing admin action variable name
$dockerfile = file_get_contents($root . '/docker/Dockerfile');
$adminUsersMigration = file_get_contents($root . '/database/migrations/20260925_000_admin_users.php');
$sharedPackageBuilder = file_get_contents($root . '/tools/release/build-shared-hosting.sh');
$releaseWorkflow = file_get_contents($root . '/.github/workflows/release-stable.yml');

$control = file_get_contents($root . '/bin/mucho');
$workflow = file_get_contents($root . '/.github/workflows/validate.yml');
$docs = file_get_contents($root . '/docs/MIGRATION_CENTER.md');
$adminPage = file_get_contents($root . '/public/admin/pages/migration.php');
$adminRouter = file_get_contents($root . '/public/admin/core/AdminRouter.php');
$adminPages = file_get_contents($root . '/public/admin/config/pages.php');
$adminActions = file_get_contents($root . '/public/admin/actions/map.php');
$adminRbac = file_get_contents($root . '/src/Admin/AdminRbac.php');
$adminIndex = file_get_contents($root . '/public/admin/index.php');

foreach ([
    'src/Migration/SourceDetector.php' => $detector,
    'bin/mucho-migrate.php' => $wizard,
    'bin/mucho-db-backup.sh' => $backupScript,
    'src/Backup/DatabaseBackupService.php' => $phpBackup,
    'src/Migration/SharedMigrationService.php' => $sharedMigration,
    'public/shared-install.php' => $sharedInstaller,
    'public/admin/db-backup-center-module.php' => $adminBackup,
    'public/admin/actions/migration.php' => $adminAction,
    'docker/Dockerfile' => $dockerfile,
    'database/migrations/20260925_000_admin_users.php' => $adminUsersMigration,
    'tools/release/build-shared-hosting.sh' => $sharedPackageBuilder,
    '.github/workflows/release-stable.yml' => $releaseWorkflow,
    'bin/mucho' => $control,
    '.github/workflows/validate.yml' => $workflow,
    'docs/MIGRATION_CENTER.md' => $docs,
] as $path => $content) {
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
}

if ($backupScript === false) {
    throw new RuntimeException('Unable to read bin/mucho-db-backup.sh');
}

if (
    $dockerfile === false ||
    $control === false ||
    $phpBackup === false ||
    $sharedMigration === false ||
    $sharedInstaller === false ||
    $adminBackup === false ||
    $adminUsersMigration === false ||
    $sharedPackageBuilder === false ||
    $releaseWorkflow === false
) {
    throw new RuntimeException('Unable to read one of the shared-hosting migration safety files.');
}

foreach ([
    'mariadb-client',
] as $needle) {
    if (strpos($dockerfile, $needle) === false) {
        throw new RuntimeException('Docker image is missing the MariaDB client required for verified backups.');
    }
}

if (strpos($control, 'compose exec -T app bash /var/www/mucho-core/bin/mucho-db-backup.sh') === false) {
    throw new RuntimeException('Control Center backup must run inside the app container.');
}

foreach ([
    'DatabaseBackupService',
    'gzopen(',
    'hash_file(\'sha256\'',
    'LOCK_EX | LOCK_NB',
    'SET FOREIGN_KEY_CHECKS=0',
] as $needle) {
    if (strpos($phpBackup, $needle) === false) {
        throw new RuntimeException('Portable PHP backup contract missing: ' . $needle);
    }
}

foreach ([
    'connectSource(',
    'SET SESSION TRANSACTION READ ONLY',
    'DatabaseBackupService',
    'new Migrator(',
    'beginTransaction()',
    'Another migration is already running',
] as $needle) {
    if (strpos($sharedMigration, $needle) === false) {
        throw new RuntimeException('Shared migration service contract missing: ' . $needle);
    }
}

foreach ([
    'shared-install.installed',
    'flock($lockHandle',
    'databasePreflight(',
    'tableCount > 0',
    'dotenvLine(\'MUCHO_SHARED_HOSTING\', \'1\')',
    'dotenvLine(\'MUCHO_DB_BACKUP_DIR\'',
    'DatabaseBackupService',
    'admin_users',
    'password_hash',
    'atomicWrite(',
] as $needle) {
    if (strpos($sharedInstaller, $needle) === false) {
        throw new RuntimeException('Shared installer safety contract missing: ' . $needle);
    }
}

if (strpos($adminBackup, 'muchodbSharedHosting()') === false ||
    strpos($adminBackup, 'DatabaseBackupService') === false ||
    strpos($adminBackup, 'MUCHO_DB_BACKUP_DIR') === false) {
    throw new RuntimeException('Shared-hosting Admin Backup Center integration is missing.');
}

if (strpos($adminActionPrevious, 'SharedMigrationService::connectSource') === false ||
    strpos($adminActionPrevious, 'sharedHosting') === false) {
    throw new RuntimeException('Shared-hosting Migration Center action path is missing.');
}

if (strpos($adminUsersMigration, 'CREATE TABLE IF NOT EXISTS admin_users') === false) {
    throw new RuntimeException('Canonical admin_users bootstrap migration is missing.');
}

if (strpos($sharedPackageBuilder, 'vendor/autoload.php') === false ||
    strpos($sharedPackageBuilder, 'public/shared-install.php') === false ||
    strpos($sharedPackageBuilder, 'SHARED_HOSTING_ARCHIVE_OK') === false) {
    throw new RuntimeException('Shared-hosting package builder contract is missing.');
}

if (strpos($releaseWorkflow, 'Build shared-hosting package') === false ||
    strpos($releaseWorkflow, 'Upload shared-hosting package') === false ||
    strpos($releaseWorkflow, 'MuchoCore-v${{ steps.marker.outputs.version }}-shared-hosting.zip') === false ||
    strpos($releaseWorkflow, 'MuchoCore-v${TAG#v}-shared-hosting.zip') === false) {
    throw new RuntimeException('Stable release workflow does not publish the shared-hosting package correctly.');
}

if (substr_count($releaseWorkflow, 'name: Build shared-hosting package') !== 1 ||
    substr_count($releaseWorkflow, 'name: Upload shared-hosting package') !== 1) {
    throw new RuntimeException('Stable release workflow must contain exactly one shared-hosting build and upload step.');
}

foreach ([
    'MUCHO_BACKUP_REQUIRED',
    'backup is already running',
    'exit 75',
    'BACKUP_OK',
    'sha256sum -c "$FINAL.sha256"',
    'gzip -t "$TMP"',
] as $needle) {
    if (strpos($backupScript, $needle) === false) {
        throw new RuntimeException('Database backup safety contract missing: ' . $needle);
    }
}

foreach ([
    'Cvolton',
    'GDPS-Maker',
    'MegaSa1nt',
    'FHGDPS',
    'Unknown GDPS schema',
    'imported_now',
    'detected_only',
    'filesystem',
] as $needle) {
    if (stripos($detector, $needle) === false) {
        throw new RuntimeException('Source detector contract missing: ' . $needle);
    }
}

foreach ([
    '--confirm=MIGRATE',
    'SET SESSION TRANSACTION READ ONLY',
    'DRY-RUN COMPLETE',
    'password_resets_required',
    'WHAT TO ENTER',
    'WHAT IS WHERE',
    'The destination will not be changed during this scan.',
    'createVerifiedTargetBackup(',
    'MUCHO_BACKUP_REQUIRED=1',
    'BACKUP_OK',
    'sha256sum -c',
    'filemtime(',
    'TARGET_BACKUP=',
    'Preparing MuchoCore schema...',
] as $needle) {
    if (strpos($wizard, $needle) === false) {
        throw new RuntimeException('Migration wizard contract missing: ' . $needle);
    }
}

if (strpos($wizard, 'Migration is already running') === false) {
    throw new RuntimeException('Migration concurrency lock is missing.');
}

if (strpos($wizard, 'createVerifiedTargetBackup(microtime(true))') === false) {
    throw new RuntimeException('Migration must request a fresh verified backup timestamp.');
}

$prepareAt = strpos($wizard, 'Preparing MuchoCore schema...');
$backupAt = strpos($wizard, 'Creating verified target database backup...');
if ($backupAt === false || $prepareAt === false || $backupAt > $prepareAt) {
    throw new RuntimeException(
        'Target backup must be created and verified before schema preparation.'
    );
}
$dryRunExitAt = strpos($wizard, 'if (!$requestedApply)');


foreach ([
    'FHGDPS / Cvolton Migration',
    'Check source',
    'Migrate supported data',
    'Old database host',
    'MUCHO_MIGRATION_SOURCE_PASS',
    'proc_open(',
] as $needle) {
    if (stripos((string)$adminPage . (string)$adminAction, $needle) === false) {
        throw new RuntimeException('Admin Migration Center contract missing: ' . $needle);
    }
}

foreach ([
    "'migration'=>'Migration Center'",
    "'migration'",
    "'migration-preview'",
    "'migration-apply'",
    "'migration' => 'system.manage'",
] as $needle) {
    if (
        strpos($adminPages . $adminActions . $adminRouter . $adminRbac, $needle) === false
    ) {
        throw new RuntimeException('Admin migration wiring missing: ' . $needle);
    }
}

if (
    strpos($adminIndex, "in_array(\$action,['migration-preview','migration-apply'],true)") === false ||
    strpos($adminIndex, "require __DIR__.'/actions/migration.php'") === false
) {
    throw new RuntimeException('Admin Migration Center action dispatch is missing.');
}

if ($prepareAt === false || $dryRunExitAt === false || $prepareAt < $dryRunExitAt) {
    throw new RuntimeException(
        'Migration schema preparation must happen after the dry-run exit.'
    );
}

foreach ([
    'Database & migrations',
    'Migration Center',
    'bin/mucho-migrate.php',
] as $needle) {
    if (strpos($control, $needle) === false) {
        throw new RuntimeException('Control Center migration integration missing: ' . $needle);
    }
}

$lowLevel = file_get_contents($root . '/bin/import-cvolton-db.php');
if ($lowLevel === false) {
    throw new RuntimeException('Unable to read bin/import-cvolton-db.php');
}

foreach ([
    'MUCHO_BACKUP_REQUIRED=1',
    'BACKUP_OK',
    'filemtime(',
    'Migration is already running',
    'createVerifiedTargetBackup(microtime(true))',
] as $needle) {
    if (strpos($lowLevel, $needle) === false) {
        throw new RuntimeException('Low-level importer safety contract missing: ' . $needle);
    }
}

foreach ([
    'Old DB host',
    'Old DB name',
    'Comments',
    'Friends/requests/blocks/messages',
    'Music/SFX files',
    'Unknown sources',
] as $needle) {
    if (stripos($docs, $needle) === false) {
        throw new RuntimeException('Migration documentation missing: ' . $needle);
    }
}

echo "migration-center-contract: OK\n";
