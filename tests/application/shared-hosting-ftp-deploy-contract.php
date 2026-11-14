<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$deploy = file_get_contents($root . '/public/deploy.php');
$worker = file_get_contents($root . '/bin/mucho-shared-deploy-worker.php');
$caddy = file_get_contents($root . '/docker/Caddyfile');
$dockerfile = file_get_contents($root . '/docker/Dockerfile');
$dockerCompose = file_get_contents($root . '/docker-compose.yml');
$page = file_get_contents($root . '/public/install/shared/index.html');
$goPage = file_get_contents($root . '/public/install/shared/go/index.html');
$sharedInstall = file_get_contents($root . '/public/shared-install.php');
$php7Wizard = file_get_contents($root . '/public/php7-setup.php');
$nativeBuilder = file_get_contents($root . '/tools/release/build-php7-native.sh');

foreach ([
    'public/deploy.php' => $deploy,
    'bin/mucho-shared-deploy-worker.php' => $worker,
    'docker/Caddyfile' => $caddy,
    'docker/Dockerfile' => $dockerfile,
    'docker-compose.yml' => $dockerCompose,
    'public/install/shared/index.html' => $page,
    'public/install/shared/go/index.html' => $goPage,
    'public/shared-install.php' => $sharedInstall,
] as $path => $content) {
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
}

foreach ([
    'if ($deploymentType === \'shared\')',
    "ftp_host",
    "ftp_username",
    "ftp_password",
    "shared_db_password",
    "shared_config",
    "type' => 'shared'",
    "mucho-shared-deploy-worker.php",
] as $needle) {
    if (!str_contains($deploy, $needle)) {
        throw new RuntimeException('Shared deployment API contract missing: ' . $needle);
    }
}

foreach ([
    "extension_loaded('ftp')",
    'ftp_connect_public',
    'ftp_ssl_connect',
    "function_exists('ftp_ssl_connect')",
    'FTPS unavailable in deployment PHP; skipping FTPS attempts.',
    'ftp_pasv',
    'ftp_open_authenticated',
    'detect_web_root',
    'ftp_chdir($ftp, $base)',
    '$actualBase = @ftp_pwd($ftp)',
    'ensure_remote_dir($ftp, $actualBase, $dir, $knownDirs)',
    'Uploading into web root:',
    'ftp_login',
    'ftp_put',
    'ftp_nb_put',
    'ftp_nb_continue',
    'ftp_put_with_heartbeat',
    'ftp_size',
    'latest_release',
    "hash_file('sha256'",
    'installer_csrf',
    'MuchoCore is installed',
    "'/health'",
    'cleanup_secrets',
    'provider_requires_browser_finalization',
    'create_browser_finalization_payload',
    'upload_browser_finalization_payload',
    'awaiting_browser',
    'in_array((string)($status[\'status\'] ?? \'\'), [\'running\', \'starting\', \'awaiting_browser\', \'browser_completed\', \'post_processing\'], true)',
    'Browser finalization completed; deployment job confirmed.',
    'mucho_auto=',
] as $needle) {
    if (!str_contains($worker, $needle)) {
        throw new RuntimeException('Shared FTP worker contract missing: ' . $needle);
    }
}

// The 5v.pl FTP login can start directly inside the live website's root.
// The special current-directory probe must be tested, while unsafe parent
// traversal remains forbidden for all other candidate paths.
if (!str_contains($worker, "if (\$candidate !== '.' && !safe_remote_candidate(\$candidate))")) {
    throw new RuntimeException('Shared web-root detection must probe the current FTP directory.');
}
if (!str_contains($worker, "\$markerName = 'muchocore-root-probe-'")
    || str_contains($worker, "\$markerName = '.muchocore-root-probe-'")) {
    throw new RuntimeException('Shared web-root marker must not be a hidden file blocked by hosting dotfile rules.');
}
if (!str_contains($worker, 'hash_equals($token, trim($response[\'body\']))')
    || !str_contains($worker, 'Web-root probe did not match for')) {
    throw new RuntimeException('Shared web-root detection must validate marker content and report HTTP probe status.');
}

if (str_contains($worker, '[21, 990]') || str_contains($worker, '[$requestedPort, 21, 990]')) {
    throw new RuntimeException('Explicit FTPS cannot use implicit-TLS port 990 as an automatic fallback.');
}
if (!str_contains($dockerfile, 'docker-php-ext-configure ftp --with-openssl-dir=/usr')
    || !str_contains($dockerfile, 'function_exists("ftp_ssl_connect")')
    || !str_contains($dockerfile, 'docker-php-ext-install pdo_mysql zip')
    || !str_contains($dockerfile, 'docker-php-ext-install ftp')
    || strpos($dockerfile, 'docker-php-ext-configure ftp --with-openssl-dir=/usr')
        <= strpos($dockerfile, 'docker-php-ext-install pdo_mysql zip')) {
    throw new RuntimeException('Deployment Docker image must include working FTP TLS support.');
}

foreach ([
    'ftp_password',
    'shared_db_password',
    'admin_password',
    'shared_config',
] as $secretFile) {
    if (!str_contains($deploy, $secretFile) || !str_contains($worker, $secretFile)) {
        throw new RuntimeException('Expected shared secret cleanup/storage contract missing: ' . $secretFile);
    }
}

if (str_contains($worker, 'ftpPassword]') || str_contains($worker, 'dbPassword]') || str_contains($worker, 'adminPassword]')) {
    throw new RuntimeException('Shared FTP worker must not log raw secret values by variable name.');
}

foreach ([
    'public/php7-setup.php' => $php7Wizard,
    'tools/release/build-php7-native.sh' => $nativeBuilder,
] as $path => $source) {
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Native installer source missing: ' . $path);
    }
}

foreach ([
    "bin2hex(random_bytes(32))",
    "mc/config/php7-setup-key.txt",
    "ftp_get(\$ftp, \$readback",
    "hash_equals(\$setupToken",
    "'/mc/public/php7-setup.php'",
    "'setup_token'",
] as $needle) {
    if (!str_contains($worker . $deploy, $needle)) {
        throw new RuntimeException('Private 5v.pl one-click setup flow missing: ' . $needle);
    }
}
// This endpoint is session-bound; the one-time capability is never included in
// status metadata, public logs, release archives or GET request query strings.
if (!str_contains($deploy, 'deployment_session_job_ok($id)')
    || !str_contains($deploy, "'setup_token' => (\$status['status'] ?? '') === 'completed'")
    || !str_contains($goPage, "setup.hash='setup='+secret")
    || !str_contains($goPage, "Открыть мастер настройки админки")
    || !str_contains($php7Wizard, "window.location.hash")
    || !str_contains($php7Wizard, "history.replaceState")
    || !str_contains($php7Wizard, "hash_equals(\$key, \$submittedKey)")
    || !str_contains($php7Wizard, "@unlink(\$keyFile)")
    || !str_contains($nativeBuilder, "mc/config/php7-setup-key.txt")
    || !str_contains($nativeBuilder, "Require all denied")
    || str_contains($goPage, "config.example.php и укажи данные БД")) {
    throw new RuntimeException('5v.pl owner setup must be one-click, private and single-use.');
}

foreach ([
    'auto_https disable_redirects',
    'The game API remains available over HTTP',
] as $needle) {
    if (!str_contains($caddy, $needle)) {
        throw new RuntimeException('Public HTTP/HTTPS Caddy contract missing: ' . $needle);
    }
}

foreach ([
    '"80:80"',
    '"443:443"',
] as $needle) {
    if (!str_contains($dockerCompose, $needle)) {
        throw new RuntimeException('Public HTTP/HTTPS port mapping contract missing: ' . $needle);
    }
}

if (!str_contains($dockerfile, 'docker-php-ext-install ftp')) {
    throw new RuntimeException('Deployment image must include PHP FTP support.');
}

if (!str_contains($dockerCompose, 'MUCHO_SHARED_DEPLOY_SOURCE: ${MUCHO_SHARED_DEPLOY_SOURCE:-stable}')) {
    throw new RuntimeException('Shared deployment source must be passed into the worker container.');
}

foreach ([
    'ТОЛЬКО 5V.PL',
    'PHP 7 native',
    'ftpHost',
    'ftpPassword',
    'ftpUser',
    'ftpSecurity',
    'ftpPort',
    'ftpPath',
    'value="auto"',
    'accountUrl',
    '.5v\\.pl',
    "provider:'5v.pl'",
    "type:'shared'",
    '/api/deploy/start',
    '/install/shared/go/',
    'AUTOSAVE_KEY',
    'saveDraft',
] as $needle) {
    if (!str_contains($page, $needle)) {
        throw new RuntimeException('5V.PL-only FTP UI contract missing: ' . $needle);
    }
}
if (str_contains($page, 'adminPasswordConfirm') || str_contains($page, 'dbPassword')) {
    throw new RuntimeException('5V.PL wizard must collect MySQL and owner secrets only on the tenant site.');
}

foreach ([
    "/install/shared/go/?job=",
    "mucho-shared-deploy-worker.php",
    "Copy logs",
    "muchodeployadminpassword",
    "Logs are read from the deployment job over HTTPS.",
    "Live installation console",
    "Continue installation in browser",
    "FTP upload and browser finalization completed successfully",
    "browser_finalization_url",
    "browser_finish",
    "/api/deploy/browser-finish",
] as $needle) {
    if (!str_contains($goPage, $needle)) {
        throw new RuntimeException('Shared FTP live console contract missing: ' . $needle);
    }
}

foreach ([
    'path /install/shared/go',
    'path /install/shared/go/ /install/shared/go/*',
    '/install/shared/go/index.html',
    '/api/deploy/browser-finish',
] as $needle) {
    if (!str_contains($caddy, $needle)) {
        throw new RuntimeException('Shared FTP live console Caddy route missing: ' . $needle);
    }
}

if (str_contains($page, 'Enter the MuchoGDPS deployment access key') || str_contains($goPage, 'Enter the MuchoGDPS deployment access key')) {
    throw new RuntimeException('Deployment UI must not ask the user for the internal deployment access key.');
}

if (!str_contains($deploy, 'function deployment_session_start') || !str_contains($deploy, "'/api/deploy/session'")) {
    throw new RuntimeException('Browser deployment session bootstrap contract missing.');
}

if (!str_contains($page, "window.location.assign('/install/shared/go/?job='+encodeURIComponent(data.job_id))")) {
    throw new RuntimeException('Shared FTP UI must redirect to the live installation console after creating a job.');
}


if (!str_contains($sharedInstall, "function notify_browser_finalization") || !str_contains($sharedInstall, "function_exists('curl_init')") || !str_contains($sharedInstall, 'Browser finalization callback failed')) {
    throw new RuntimeException('Shared installer browser finalization must be non-fatal.');
}

if (!str_contains($sharedInstall, 'function shared_installer_fatal_guard') || !str_contains($sharedInstall, 'shared-install-errors.log')) {
    throw new RuntimeException('Shared installer must record and surface PHP fatal diagnostics.');
}
echo "shared-hosting-ftp-deploy-contract: OK\n";
