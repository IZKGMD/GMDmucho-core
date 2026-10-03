<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MuchoCore\Client\DeploymentClientPack;
use MuchoCore\Core\Environment;

$rootDir = dirname(__DIR__);

$serverUrl = trim((string)(Environment::get(
    'MUCHO_ACCOUNT_URL',
    ''
) ?? ''));

if ($serverUrl === '') {
    $domain = trim((string)(Environment::get('DOMAIN', '') ?? ''));
    if ($domain !== '') {
        $serverUrl = 'https://' . $domain;
    }
}

if ($serverUrl === '') {
    fwrite(STDERR, "[MuchoCore] Client patch failed: MUCHO_ACCOUNT_URL/DOMAIN is not configured.\n");
    exit(2);
}

$serverName = trim((string)(Environment::get(
    'MUCHO_SERVER_NAME',
    'Mucho GDPS'
) ?? 'Mucho GDPS'));

$workDir = $rootDir . '/storage/control/client-patch';
$storageDir = $rootDir . '/storage/clients';

if (!is_dir($workDir) && !mkdir($workDir, 0700, true) && !is_dir($workDir)) {
    fwrite(STDERR, "[MuchoCore] Client patch failed: cannot create work directory.\n");
    exit(1);
}

if (!is_dir($storageDir) && !mkdir($storageDir, 0750, true) && !is_dir($storageDir)) {
    fwrite(STDERR, "[MuchoCore] Client patch failed: cannot create client storage.\n");
    exit(1);
}

$lockPath = $workDir . '/run.lock';
$lock = fopen($lockPath, 'c');

if ($lock === false || !flock($lock, LOCK_EX)) {
    if (is_resource($lock)) {
        fclose($lock);
    }
    fwrite(STDERR, "[MuchoCore] Client patch failed: another patch operation is active.\n");
    exit(1);
}

try {
    $manifest = DeploymentClientPack::prepare(
        $rootDir,
        $workDir,
        $serverUrl,
        $serverName
    );

    $copies = [
        [$manifest['windows']['path'] ?? '', $storageDir . '/GeometryDash-MuchoGDPS.exe'],
        [$manifest['android']['path'] ?? '', $storageDir . '/GeometryDash-MuchoGDPS.apk'],
        [$manifest['archive']['path'] ?? '', $storageDir . '/MuchoGDPS-Client-Pack.zip'],
    ];

    foreach ($copies as [$source, $destination]) {
        if (!is_string($source) || $source === '' || !is_file($source) || !is_readable($source)) {
            throw new RuntimeException('Generated client artifact is unavailable.');
        }

        if (!copy($source, $destination)) {
            throw new RuntimeException('Unable to publish generated client artifact.');
        }

        @chmod($destination, 0640);
    }

    $tenantManifest = $manifest;
    foreach (['windows', 'android', 'archive'] as $key) {
        if (isset($tenantManifest[$key]['path'])) {
            unset($tenantManifest[$key]['path']);
        }
    }

    $tenantManifest['published_at'] = gmdate('c');
    $tenantManifest['auto_patch'] = [
        'enabled' => true,
        'engine' => '2.0',
        'server_url' => $serverUrl,
    ];

    $manifestPath = $storageDir . '/manifest.json';
    if (
        file_put_contents(
            $manifestPath,
            json_encode(
                $tenantManifest,
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE |
                JSON_PRETTY_PRINT |
                JSON_THROW_ON_ERROR
            ),
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException('Unable to publish client manifest.');
    }

    @chmod($manifestPath, 0640);

    $windowsSha = (string)($manifest['windows']['sha256'] ?? '');
    $androidSha = (string)($manifest['android']['sha256'] ?? '');
    $packSha = (string)($manifest['archive']['sha256'] ?? '');

    echo "[MuchoCore] Auto Patch 2.0 complete.\n";
    echo "[MuchoCore] Server: {$serverUrl}\n";
    echo "[MuchoCore] Windows SHA-256: {$windowsSha}\n";
    echo "[MuchoCore] Android SHA-256: {$androidSha}\n";
    echo "[MuchoCore] Client Pack SHA-256: {$packSha}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(
        STDERR,
        "[MuchoCore] Auto Patch 2.0 failed: " . $e->getMessage() . "\n"
    );
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
