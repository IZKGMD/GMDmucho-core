<?php

declare(strict_types=1);

/* Copyright (C) 2026 IZK */

$root = dirname(__DIR__);

$dockerfile = file_get_contents($root . '/docker/Dockerfile');
$compose = file_get_contents($root . '/docker-compose.yml');
$env = file_get_contents($root . '/.env.example');

foreach ([
    'docker/Dockerfile' => $dockerfile,
    'docker-compose.yml' => $compose,
    '.env.example' => $env,
] as $file => $content) {
    if ($content === false) {
        throw new RuntimeException($file . ' is missing.');
    }
}

foreach ([
    'upload_max_filesize=256M',
    'post_max_size=320M',
    'memory_limit=512M',
] as $needle) {
    if (!str_contains($dockerfile, $needle)) {
        throw new RuntimeException(
            'Docker PHP setting missing: ' . $needle
        );
    }
}

if (!str_contains($compose, '--max_allowed_packet=320M')) {
    throw new RuntimeException(
        'MariaDB max_allowed_packet is not configured for large payloads.'
    );
}

if (!str_contains($env, 'MUCHO_LEVEL_MAX_MB=32')) {
    throw new RuntimeException(
        'Default level size setting is missing.'
    );
}

echo "DEPLOYMENT_CONTRACT_OK\n";
