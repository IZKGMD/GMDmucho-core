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
$ownerPosition = strpos($installer, 'FirstRunSetup::createOwner($pdo, $adminUser, $adminPass)');
$bootstrapWritePosition = strpos($installer, 'atomicWrite($bootstrapPath, $bootstrap, 0600)');
if (
    $verifyPosition === false ||
    $ownerPosition === false ||
    $bootstrapWritePosition === false ||
    $verifyPosition < $bootstrapWritePosition ||
    $ownerPosition <= $verifyPosition ||
    !str_contains($installer, 'if ($tableCount === 0)') ||
    !str_contains($installer, '<a href="/admin/">Sign in to the admin panel</a>') ||
    str_contains($installer, 'Create first administrator (private activation link)') ||
    str_contains($installer, 'One-time admin setup secret')
) {
    throw new RuntimeException(
        'Shared installation must create the first owner only on fresh databases and lead directly to sign-in.'
    );
}

$php7Setup = file_get_contents($root . '/public/php7-setup.php');
$php7View = file_get_contents($root . '/compat/php7/admin-view.php');
$php7Admin = file_get_contents($root . '/compat/php7/admin.php');
if (!is_string($php7Setup) || !is_string($php7View) || !is_string($php7Admin)
    || !str_contains($php7Setup, 'mucho7_admin_bootstrap($pdo, $options')
    || !str_contains($php7Setup, 'admin_password_confirm')
    || !str_contains($php7Setup, 'href="php7-admin.php?page=system"')
    || !str_contains($php7View, 'name="password_confirm"')
    || !str_contains($php7View, 'name="db_password"')
    || !str_contains($php7Admin, "array_key_exists('password_confirm',\$data)")
    || !str_contains($php7Admin, 'if (!$verifiedInstaller)')
) {
    throw new RuntimeException('The native PHP7 first-owner wizard must collect the login once and preserve ownership verification.');
}

// PHP7 first-run must take the owner to setup, not strand them in a disabled page.
$nativeAdminPage = file_get_contents($root . '/public/php7-admin.php');
$nativeUpgrade = file_get_contents($root . '/compat/php7/core-update.php');
foreach ([
    'installer explains final setup' => str_contains($php7Setup, 'Шаг 2 из 3: автоматическое включение ядра'),
    'new owner goes to Server' => str_contains($nativeAdminPage, "header('Location: ?page.'." ) || str_contains($nativeAdminPage, "header('Location: ?page='.\$nextPage"),
    'requires unfinished marker' => str_contains($nativeAdminPage, "config/php7-complete.on"),
    'blocked page shows inline migration action' => str_contains($php7View, "mucho7_core_render(\$db,\$root);"),
    'migration continuation has CSP nonce' => str_contains($nativeUpgrade, "nonce=\"'.h(\$nonce).'\""),
    'successful setup links to patcher' => str_contains($nativeUpgrade, 'href="?page=clientpatcher"'),
    'setup explicitly shows migration stage' => str_contains($nativeUpgrade, 'Step 2 of 3'),
    'setup explicitly shows game client stage' => str_contains($nativeUpgrade, 'Step 3 of 3'),
    'migration is resumable in guide' => str_contains($nativeUpgrade, 'Continue migration'),
    'build update separate from first run' =>
        str_contains($nativeUpgrade, '$isOwner&&$active&&!$transition&&is_file'),
    'global owner setup reminder' => str_contains($php7View, 'Setup incomplete: Step 2 of 3'),
    'client patcher preserves original renderer' =>
        str_contains($php7View, "elseif(\$page==='clientpatcher')require"),
    'native wizard explains client tests' => str_contains($php7Setup, 'тестовыми аккаунтами'),
    'native wizard opens authenticated admin next' =>
        str_contains($php7Setup, "window.location.replace('php7-admin.php?page=system')"),
    'owner login explicitly starts migration' =>
        str_contains($nativeAdminPage, "\$_SESSION['core_auto_start']=1;")
        && str_contains($nativeAdminPage, "\$_SESSION['core_auto_flow']=1;"),
    'automatic activation limited to owner' =>
        str_contains($nativeAdminPage, "\$needsCore=\$loginUser['role']==='owner'"),
    'migration remains authenticated and CSRF-protected' =>
        str_contains($nativeAdminPage, "if(!\$user){http_response_code(403)")
        && str_contains($nativeAdminPage, "hash_equals(\$_SESSION['csrf'],\$_POST['csrf'])"),
    'automatic activation consumes one-time session state' =>
        str_contains($nativeUpgrade, "unset(\$_SESSION['core_auto_start']);"),
    'auto continuation uses authenticated POST and CSP nonce' =>
        str_contains($nativeUpgrade, "form.requestSubmit ? form.requestSubmit() : form.submit()")
        && str_contains($nativeUpgrade, "nonce=\"'.h(\$nonce).'\""),
    'automatic completion opens client patcher' =>
        str_contains($nativeAdminPage, "header('Location: ?page=clientpatcher',true,303)"),
    'failures stop automatic migration' =>
        str_contains($nativeAdminPage, "unset(\$_SESSION['core_upgrade_continue'],\$_SESSION['core_auto_start'],\$_SESSION['core_auto_flow'])"),
] as $case => $ok) {
    if (!$ok) {
        throw new RuntimeException('PHP7 first-run onboarding broken: ' . $case);
    }
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
    'activation only via POST' => str_contains($wizard, "\$action === 'activate'"),
    'no visible setup-key field' => !str_contains($wizard, '<input id="setup_key"'),
    'shared installer creates fresh owner once' =>
        str_contains($installer, 'FirstRunSetup::createOwner($pdo, $adminUser, $adminPass)')
        && str_contains($installer, 'if ($tableCount === 0)')
        && !str_contains($installer, '/admin/#setup='),

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
    || !str_contains($vpsInstaller, 'owner-initialize-pending')
    || !str_contains($vpsInstaller, 'owner-browser-pending')
    || !str_contains($vpsInstaller, 'php bin/mucho-owner-link.php')
    || !str_contains($vpsInstaller, 'php bin/mucho-initialize-owner.php --initialize')
    || str_contains($vpsInstaller, 'MUCHO_ADMIN_PASSWORD="$(openssl rand -hex 32)"')
    || !str_contains($vpsInstaller, 'collect_first_owner_credentials()')
    || !str_contains($vpsInstaller, 'Step 1 — Create your first administrator')
) {
    throw new RuntimeException('Owner installation and legacy activation safety contract broken.');
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
