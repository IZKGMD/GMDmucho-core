<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$deploy = file_get_contents($root . '/public/deploy.php');
$worker = file_get_contents($root . '/bin/mucho-shared-deploy-worker.php');
$caddy = file_get_contents($root . '/docker/Caddyfile');
$dockerfile = file_get_contents($root . '/docker/Dockerfile');
$page = file_get_contents($root . '/public/install/shared/index.html');
$goPage = file_get_contents($root . '/public/install/shared/go/index.html');

foreach ([
    'public/deploy.php' => $deploy,
    'bin/mucho-shared-deploy-worker.php' => $worker,
    'docker/Caddyfile' => $caddy,
    'docker/Dockerfile' => $dockerfile,
    'public/install/shared/index.html' => $page,
    'public/install/shared/go/index.html' => $goPage,
] as $path => $content) {
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
}

foreach ([
    "if ($deploymentType === 'shared')",
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
    'ftp_pasv',
    'ftp_open_authenticated',
    'detect_web_root',
    'ftp_login',
    'ftp_put',
    'latest_release',
    "hash_file('sha256',
    'installer_csrf',
    'MuchoCore is installed',
    "'/health'",
    'cleanup_secrets',
] as $needle) {
    if (!str_contains($worker, $needle)) {
        throw new RuntimeException('Shared FTP worker contract missing: ' . $needle);
    }
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
    'auto_https disable_redirects',
    '"80:80"',
    '"443:443"',
    'The game API remains available over HTTP',
] as $needle) {
    if (!str_contains($caddy, $needle)) {
        throw new RuntimeException('Public HTTP/HTTPS contract missing: ' . $needle);
    }
}

if (!str_contains($dockerfile, 'pdo_mysql zip ftp')) {
    throw new RuntimeException('Deployment image must include PHP FTP support.');
}

foreach ([
    'FTP / FTPS deployment',
    'ftp_host',
    'ftp_password',
    'db_password',
    '/api/deploy/start',
    'type:\'shared\'',
    'Start automatic deployment',
    'Auto Detect (recommended)',
    'Auto detect web root',
    'value="auto"',
    '/install/shared/go/',
    'AUTOSAVE_KEY',
    'restoreDraft',
    'sessionStorage',
] as $needle) {
    if (!str_contains($page, $needle)) {
        throw new RuntimeException('Shared FTP UI contract missing: ' . $needle);
    }
}

foreach ([
    "/install/shared/go/?job=",
    "mucho-shared-deploy-worker.php",
    "Copy logs",
    "muchodeployadminpassword",
    "Every message written by the shared-hosting FTP worker",
    "Live installation console",
] as $needle) {
    if (!str_contains($goPage, $needle)) {
        throw new RuntimeException('Shared FTP live console contract missing: ' . $needle);
    }
}

foreach ([
    'path /install/shared/go',
    'path /install/shared/go/ /install/shared/go/*',
    '/install/shared/go/index.html',
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

if (!str_contains($page, "window.location.assign('/install/shared/go/?job='+encodeURIComponent(d.job_id))")) {
    throw new RuntimeException('Shared FTP UI must redirect to the live installation console after creating a job.');
}

echo "shared-hosting-ftp-deploy-contract: OK\n";
