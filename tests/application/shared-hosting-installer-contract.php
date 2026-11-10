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
$bootstrapWritePosition = strpos($installer, 'atomicWrite($bootstrapPath, $bootstrap, 0600)');
if (
    $verifyPosition === false ||
    $bootstrapWritePosition === false ||
    $verifyPosition < $bootstrapWritePosition ||
    str_contains($installer, '$adminStmt->execute([')
) {
    throw new RuntimeException(
        'Shared installation must persist setup-secret hash, verify the schema, and defer owner creation until /admin/.'
    );
}

$admin = file_get_contents($root . '/public/admin/index.php');
$wizard = file_get_contents($root . '/public/admin/first-run.php');
$firstRun = file_get_contents($root . '/src/Admin/FirstRunSetup.php');
$adminSync = file_get_contents($root . '/bin/mucho-sync-admin.php');
$ownerLink = file_get_contents($root . '/bin/mucho-owner-link.php');
$vpsInstaller = file_get_contents($root . '/install.sh');
foreach ([
    'index' => $admin,
    'wizard' => $wizard,
    'first-run service' => $firstRun,
    'admin sync' => $adminSync,
] as $name => $source) {
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('First-run code missing: ' . $name);
    }
}
require_once $root . '/public/admin/first-run.php';
foreach ([
    'new browser defaults to English' => muchoAdminFirstRunLanguage([], []) === 'en',
    'previously chosen Russian remains selected' =>
        muchoAdminFirstRunLanguage([], ['mucho_admin_language' => 'ru']) === 'ru',
    'query English overrides Russian cookie' =>
        muchoAdminFirstRunLanguage(['lang' => 'en'], ['mucho_admin_language' => 'ru']) === 'en',
    'query Russian overrides English cookie' =>
        muchoAdminFirstRunLanguage(['lang' => 'ru'], ['mucho_admin_language' => 'en']) === 'ru',
    'unknown locale falls back to English' =>
        muchoAdminFirstRunLanguage(['lang' => 'de'], []) === 'en',
    'array query input cannot select a locale' =>
        muchoAdminFirstRunLanguage(['lang' => ['ru']], []) === 'en',
    'English first-run heading' =>
        muchoAdminFirstRunCopy('en')['heading'] === 'Create first administrator',
    'Russian first-run heading' =>
        muchoAdminFirstRunCopy('ru')['heading'] === 'Создать первого администратора',
    'all first-run messages have Russian translations' =>
        array_keys(muchoAdminFirstRunCopy('en')) === array_keys(muchoAdminFirstRunCopy('ru')),
    'English error translations exist' =>
        str_contains(muchoAdminFirstRunCopy('en')['secret_invalid'], 'activation link'),
    'language selector rendered on the first-run page' =>
        str_contains($wizard, 'class="language-switcher"'),
    'selected language persists via admin cookie' =>
        str_contains($wizard, "setcookie('mucho_admin_language'"),
    'selected language persists after form validation' =>
        str_contains($wizard, 'action="/admin/?lang='),
] as $name => $ok) {
    if (!$ok) {
        throw new RuntimeException('First-run language contract broken: ' . $name);
    }
}

foreach ([
    'secret checked with password_verify' => str_contains($wizard, 'password_verify($setupKey, $hash)'),
    'owner activation session required' => str_contains($wizard, 'mucho_first_owner_authorized_until'),
    'registration rejects unverified visitors' => str_contains($wizard, 'elseif (!$authorized)'),
    'activation strips fragment from history' => str_contains($wizard, 'history.replaceState'),
    'activation only via POST' => str_contains($wizard, "if ($action === 'activate')"),
    'no visible setup-key field' => !str_contains($wizard, '<input id="setup_key"'),
    'shared installer provides a private activation link' =>
        str_contains($installer, '/admin/#setup='),

    'CSRF check before enrollment' => str_contains($wizard, 'hash_equals($csrf, $providedCsrf)'),
    'race-safe database lock' => str_contains($firstRun, 'GET_LOCK(:name, 5)'),
    'one-time completion marker' => str_contains($firstRun, 'admin_initialization'),
    'admin panel calls owner onboarding' => str_contains($admin, 'muchoAdminFirstRun('),
    'routine sync preserves existing credentials' => str_contains($adminSync, 'if (!$resetRequested)'),
    'CLI reset requires explicit flag' => str_contains($adminSync, "'--reset-password'"),
] as $name => $ok) {
    if (!$ok) {
        throw new RuntimeException('First-run safety contract broken: ' . $name);
    }
}

if (!is_string($ownerLink) || !str_contains($ownerLink, "PHP_SAPI !== 'cli'")
    || !str_contains($ownerLink, "FirstRunSetup::prepare")
    || !str_contains($ownerLink, 'password_verify($secret, $hash)')
    || !str_contains($ownerLink, '/admin/#setup=')
    || !is_string($vpsInstaller)
    || !str_contains($vpsInstaller, 'MUCHO_ADMIN_PASSWORD="$(openssl rand -hex 32)"')
    || str_contains($vpsInstaller, 'prompt_admin_password()')
) {
    throw new RuntimeException('Private owner activation link / installer contract broken.');
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
