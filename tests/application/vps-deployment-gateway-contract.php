<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$deploy = file_get_contents($root . '/public/deploy.php');
$caddy = file_get_contents($root . '/docker/Caddyfile');
$compose = file_get_contents($root . '/docker-compose.yml');
$dockerfile = file_get_contents($root . '/docker/Dockerfile');
$env = file_get_contents($root . '/.env.example');

foreach ([
    'public/deploy.php' => $deploy,
    'docker/Caddyfile' => $caddy,
    'docker-compose.yml' => $compose,
    'docker/Dockerfile' => $dockerfile,
    '.env.example' => $env,
] as $path => $content) {
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
}

foreach ([
    'if ($method === \'POST\' && $path === \'/api/deploy/start\')',
    "type' => 'vps'",
    '$worker = $root . \'/bin/mucho-deploy-worker.php\';',
    "function deployment_queue_dispatch",
    "DeploymentClientPack::prepare",
    "'/api/deploy/client-pack'",
] as $needle) {
    if (!str_contains($deploy, $needle)) {
        throw new RuntimeException('VPS deployment gateway contract missing: ' . $needle);
    }
}

foreach ([
    "ftp_host",
    "ftp_username",
    "ftp_password",
    "shared_db_password",
    "shared_config",
    "mucho-shared-deploy-worker.php",
    "browser-finalization",
    "/api/deploy/browser-finish",
    "MUCHO_SHARED_DEPLOY_SOURCE",
] as $forbidden) {
    if (str_contains($deploy, $forbidden)) {
        throw new RuntimeException('VPS deployment gateway contains removed shared-hosting code: ' . $forbidden);
    }
}

foreach ([
    "path /api/deploy/start /api/deploy/status /api/deploy/log",
    "path /install/vps",
    "path /install /install/*",
] as $needle) {
    if (!str_contains($caddy, $needle)) {
        throw new RuntimeException('VPS Caddy routing contract missing: ' . $needle);
    }
}

foreach ([
    "/install/shared/",
    "/shared-install.php",
    "/ftp-install.php",
    "mucho-shared-deploy-worker.php",
    "browser-finish",
] as $forbidden) {
    if (str_contains($caddy, $forbidden)) {
        throw new RuntimeException('Caddy still exposes removed shared-hosting routing: ' . $forbidden);
    }
}

if (str_contains($compose, 'MUCHO_SHARED_DEPLOY_SOURCE')) {
    throw new RuntimeException('Docker Compose still contains shared deployment configuration.');
}
if (str_contains($dockerfile, 'docker-php-ext-install pdo_mysql zip ftp')) {
    throw new RuntimeException('Production image still installs the removed PHP FTP extension.');
}
if (str_contains($env, 'MUCHO_SHARED_DEPLOY_SOURCE') || str_contains($env, 'MUCHO_SHARED_HOSTING')) {
    throw new RuntimeException('Environment template still exposes removed shared-hosting settings.');
}

echo "vps-deployment-gateway-contract: OK\n";
