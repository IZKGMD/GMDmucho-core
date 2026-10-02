<?php

declare(strict_types=1);

namespace MuchoCore\Client;

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
        string $serverUrl
    ): array {
        $server = WindowsClientPatcher::validateServerUrl($serverUrl);
        $sourceExe = $rootDir . '/GeometryDash.exe';
        $sourceApk = self::androidSource($rootDir);

        if (!is_file($sourceExe) || !is_readable($sourceExe)) {
            throw new RuntimeException(
                'Built-in Geometry Dash Windows client is unavailable on this control server.'
            );
        }

        if (!is_file($sourceApk) || !is_readable($sourceApk)) {
            throw new RuntimeException(
                'Built-in Geometry Dash Android APK is unavailable. Upload the base APK to patched/apk/ or set MUCHO_ANDROID_BASE_APK.'
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
                is_file((string)($existing['android']['path'] ?? ''))
            ) {
                return $existing;
            }

            $windowsPath = $jobDir . '/GeometryDash-MuchoGDPS.exe';
            $androidPath = $jobDir . '/GeometryDash-MuchoGDPS.apk';

            @unlink($windowsPath);
            @unlink($androidPath);

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

            $manifest = [
                'server_url' => $server,
                'created_at' => gmdate('c'),
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
        $configured = trim((string)(getenv('MUCHO_ANDROID_BASE_APK') ?: ''));
        if ($configured !== '') {
            return $configured;
        }

        $dir = $rootDir . '/patched/apk';
        $preferred = $dir . '/GeometryDash_2.2.13_MuchoGDPS_Unique_v2.apk';
        if (is_file($preferred)) {
            return $preferred;
        }

        $files = glob($dir . '/*.apk') ?: [];
        usort(
            $files,
            static fn(string $a, string $b): int =>
                (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0)
        );

        return (string)($files[0] ?? '');
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
