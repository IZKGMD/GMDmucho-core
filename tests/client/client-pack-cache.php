<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Client/DeploymentClientPack.php';

use MuchoCore\Client\DeploymentClientPack;

function checkCache(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('GD 2.209 cache regression: ' . $message);
    }
}

$dir = sys_get_temp_dir() . '/muchocore-client-cache-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700)) {
    throw new RuntimeException('Cannot create cache test directory.');
}

$paths = [];
try {
    foreach (['windows', 'android', 'archive'] as $type) {
        $paths[$type] = $dir . '/' . $type . '.out';
        file_put_contents($paths[$type], $type);
    }
    $hashes = [
        'windows' => hash('sha256', 'Original GD 2.209.exe'),
        'android' => hash('sha256', 'Existing Android.apk'),
    ];
    $manifest = [
        'server_url' => 'https://muchogdps.space',
        'source_sha256' => $hashes,
        'windows' => ['path' => $paths['windows']],
        'android' => ['path' => $paths['android']],
        'archive' => ['path' => $paths['archive']],
    ];

    checkCache(DeploymentClientPack::canReuseClientPack($manifest, 'https://muchogdps.space', $hashes), 'unchanged sources should hit the cache');
    checkCache(!DeploymentClientPack::canReuseClientPack($manifest, 'https://another.example', $hashes), 'server change must invalidate cache');
    checkCache(!DeploymentClientPack::canReuseClientPack($manifest, 'https://muchogdps.space', [
        'windows' => hash('sha256', 'Updated EXE'),
        'android' => $hashes['android'],
    ]), 'Windows EXE change must invalidate cache');
    checkCache(!DeploymentClientPack::canReuseClientPack($manifest, 'https://muchogdps.space', [
        'windows' => $hashes['windows'],
        'android' => hash('sha256', 'Updated APK'),
    ]), 'Android APK change must invalidate cache');
    $legacy = $manifest;
    unset($legacy['source_sha256']);
    checkCache(!DeploymentClientPack::canReuseClientPack($legacy, 'https://muchogdps.space', $hashes), 'legacy cache without source hashes must be rebuilt');
    unlink($paths['windows']);
    checkCache(!DeploymentClientPack::canReuseClientPack($manifest, 'https://muchogdps.space', $hashes), 'missing output artifact must invalidate cache');
} finally {
    foreach ($paths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($dir);
}

echo "MUCHOCORE_CLIENT_SOURCE_CACHE_OK\n";
