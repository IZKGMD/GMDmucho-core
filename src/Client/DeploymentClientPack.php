<?php

declare(strict_types=1);

namespace MuchoCore\Client;

use MuchoCore\Core\Environment;

use RuntimeException;

final class DeploymentClientPack
{
    public static function token(string $jobDir): string
    {
        $path = $jobDir . '/client-pack-token';

        if (is_file($path)) {
            $token = trim((string)file_get_contents($path));
            if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
                return $token;
            }
        }

        $token = bin2hex(random_bytes(32));
        if (@file_put_contents($path, $token, LOCK_EX) === false) {
            throw new RuntimeException('Cannot create client pack token.');
        }

        @chmod($path, 0600);
        return $token;
    }

    public static function prepare(
        string $rootDir,
        string $jobDir,
        string $serverUrl,
        string $serverName = ''
    ): array {
        $server = WindowsClientPatcher::validateServerUrl($serverUrl);
        $serverName = trim($serverName);
        if ($serverName === '') {
            $serverName = trim((string)(Environment::get('MUCHO_SERVER_NAME', 'Mucho GDPS') ?? 'Mucho GDPS'));
        }
        $sourceExe = $rootDir . '/GeometryDash.exe';
        $sourceApk = self::androidSource($rootDir, $jobDir);

        if (!is_file($sourceExe) || !is_readable($sourceExe)) {
            throw new RuntimeException(
                'Built-in Geometry Dash Windows client is unavailable on this control server.'
            );
        }

        if (!is_file($sourceApk) || !is_readable($sourceApk)) {
            $candidates = self::androidSourceCandidates($rootDir);
            throw new RuntimeException(
                'Built-in Geometry Dash Android APK is unavailable. Checked: ' .
                implode(', ', $candidates) .
                '. Upload the base APK to patched/apk/ or set MUCHO_ANDROID_BASE_APK to a readable path inside the MuchoCore container.'
            );
        }

        $lockPath = $jobDir . '/client-pack.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot create client pack lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock client pack generation.');
            }

            $manifestPath = $jobDir . '/client-pack.json';
            $existing = is_file($manifestPath)
                ? json_decode((string)file_get_contents($manifestPath), true)
                : null;

            if (
                is_array($existing) &&
                ($existing['server_url'] ?? '') === $server &&
                is_file((string)($existing['windows']['path'] ?? '')) &&
                is_file((string)($existing['android']['path'] ?? '')) &&
                is_file((string)($existing['archive']['path'] ?? ''))
            ) {
                return $existing;
            }

            $windowsPath = $jobDir . '/GeometryDash-MuchoGDPS.exe';
            $androidPath = $jobDir . '/GeometryDash-MuchoGDPS.apk';
            $archivePath = $jobDir . '/MuchoGDPS-Client-Pack.zip';

            @unlink($windowsPath);
            @unlink($androidPath);
            @unlink($archivePath);

            $windows = WindowsClientPatcher::patchFile(
                $sourceExe,
                $windowsPath,
                $server
            );

            $android = AndroidClientPatcher::patchFile(
                $sourceApk,
                $androidPath,
                $server
            );

            $createdAt = gmdate('c');
            $publicManifest = [
                'patch_engine' => '2.0',
                'server_url' => $server,
                'server_name' => $serverName,
                'created_at' => $createdAt,
                'files' => [
                    [
                        'name' => 'windows/GeometryDash-MuchoGDPS.exe',
                        'size' => (int)$windows['output_size'],
                        'sha256' => (string)$windows['output_sha256'],
                    ],
                    [
                        'name' => 'android/GeometryDash-MuchoGDPS.apk',
                        'size' => (int)$android['output_size'],
                        'sha256' => (string)$android['output_sha256'],
                    ],
                ],
            ];

            $zip = new \ZipArchive();
            if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create the client pack ZIP archive.');
            }

            try {
                if (!$zip->addFile($windowsPath, 'windows/GeometryDash-MuchoGDPS.exe')) {
                    throw new RuntimeException('Cannot add the Windows client to the client pack ZIP.');
                }
                if (!$zip->addFile($androidPath, 'android/GeometryDash-MuchoGDPS.apk')) {
                    throw new RuntimeException('Cannot add the Android client to the client pack ZIP.');
                }
                if (!$zip->addFromString(
                    'client-pack.json',
                    json_encode($publicManifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
                )) {
                    throw new RuntimeException('Cannot add the client pack manifest to the ZIP archive.');
                }
            } finally {
                $zip->close();
            }

            $archiveSize = filesize($archivePath);
            $archiveSha256 = hash_file('sha256', $archivePath);
            if (
                $archiveSize === false ||
                $archiveSize < 1024 ||
                !is_string($archiveSha256) ||
                $archiveSha256 === ''
            ) {
                throw new RuntimeException('Generated client pack ZIP archive is invalid.');
            }

            $manifest = [
                'patch_engine' => '2.0',
                'server_url' => $server,
                'server_name' => $serverName,
                'created_at' => $createdAt,
                'windows' => [
                    'path' => $windowsPath,
                    'name' => 'GeometryDash-MuchoGDPS.exe',
                    'size' => (int)$windows['output_size'],
                    'sha256' => (string)$windows['output_sha256'],
                    'replacement_count' => (int)$windows['replacement_count'],
                ],
                'android' => [
                    'path' => $androidPath,
                    'name' => 'GeometryDash-MuchoGDPS.apk',
                    'size' => (int)$android['output_size'],
                    'sha256' => (string)$android['output_sha256'],
                    'replacement_count' => (int)$android['replacement_count'],
                ],
                'archive' => [
                    'path' => $archivePath,
                    'name' => 'MuchoGDPS-Client-Pack.zip',
                    'size' => (int)$archiveSize,
                    'sha256' => $archiveSha256,
                ],
            ];

            file_put_contents(
                $manifestPath,
                json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                LOCK_EX
            );
            @chmod($manifestPath, 0600);

            return $manifest;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function androidSource(string $rootDir): string
    {
        $configured = trim((string)(Environment::get('MUCHO_ANDROID_BASE_APK', '') ?? ''));
        if ($configured !== '' && is_file($configured) && is_readable($configured)) {
            return $configured;
        }

        $dir = $rootDir . '/patched/apk';
        $preferred = $dir . '/GeometryDash_2.2.13_MuchoGDPS_Unique_v2.apk';
        if (is_file($preferred) && is_readable($preferred)) {
            return $preferred;
        }

        $files = glob($dir . '/*.apk') ?: [];
        $files = array_values(array_filter(
            $files,
            static fn(string $path): bool => is_file($path) && is_readable($path)
        ));
        usort(
            $files,
            static fn(string $a, string $b): int =>
                (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0)
        );

        return (string)($files[0] ?? '');
    }

    public static function androidSourceCandidates(string $rootDir): array
    {
        $configured = trim((string)(Environment::get('MUCHO_ANDROID_BASE_APK', '') ?? ''));
        $dir = $rootDir . '/patched/apk';

        $candidates = [];
        if ($configured !== '') {
            $candidates[] = $configured;
        }
        $candidates[] = $dir . '/GeometryDash_2.2.13_MuchoGDPS_Unique_v2.apk';

        foreach (glob($dir . '/*.apk') ?: [] as $path) {
            $candidates[] = (string)$path;
        }

        return array_values(array_unique($candidates));
    }

    public static function manifest(string $jobDir): array
    {
        $path = $jobDir . '/client-pack.json';
        $data = is_file($path)
            ? json_decode((string)file_get_contents($path), true)
            : null;

        if (!is_array($data)) {
            throw new RuntimeException('Client pack has not been prepared yet.');
        }

        return $data;
    }
}
