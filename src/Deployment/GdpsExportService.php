<?php

declare(strict_types=1);

namespace MuchoCore\Deployment;

use MuchoCore\Backup\DatabaseBackupService;
use MuchoCore\Core\Environment;
use MuchoCore\Database\Migrator;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

final class GdpsExportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $rootDir,
        private readonly string $outputDir
    ) {}

    public function create(): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZIP support is required for GDPS export.');
        }

        $outputDir = rtrim($this->outputDir, '/\\');
        if (
            !is_dir($outputDir) &&
            !mkdir($outputDir, 0750, true) &&
            !is_dir($outputDir)
        ) {
            throw new RuntimeException('Unable to create export directory.');
        }

        $lock = fopen($outputDir . '/.export.lock', 'c');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Another GDPS export is already running.');
        }

        try {
            $backupDir = $outputDir . '/database';
            $backup = (new DatabaseBackupService(
                $this->pdo,
                $backupDir
            ))->create('gdps-export');

            $timestamp = gmdate('Ymd_His');
            $zipPath = $outputDir . '/muchocore-gdps-export_' . $timestamp . '.zip';
            $shaPath = $zipPath . '.sha256';

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create GDPS export archive.');
            }

            try {
                if (!$zip->addFile(
                    $backup['file'],
                    'database/' . basename($backup['file'])
                )) {
                    throw new RuntimeException('Unable to add database backup to export.');
                }

                if (
                    is_file($backup['file'] . '.sha256') &&
                    !$zip->addFile(
                        $backup['file'] . '.sha256',
                        'database/' . basename($backup['file']) . '.sha256'
                    )
                ) {
                    throw new RuntimeException('Unable to add database checksum to export.');
                }

                $clientManifest = rtrim($this->rootDir, '/\\')
                    . '/storage/clients/manifest.json';

                if (is_file($clientManifest)) {
                    $zip->addFile(
                        $clientManifest,
                        'clients/manifest.json'
                    );
                }

                $envExample = rtrim($this->rootDir, '/\\') . '/.env.example';
                if (is_file($envExample)) {
                    $zip->addFile($envExample, 'configuration/.env.example');
                }

                $migrationStatus = $this->migrationStatus();
                $zip->addFromString(
                    'migrations/status.txt',
                    $migrationStatus
                );

                $serverInfo = [
                    'format' => 'muchocore-gdps-export-v1',
                    'created_at' => gmdate('c'),
                    'core_version' => $this->version(),
                    'server_name' => $this->env('MUCHO_SERVER_NAME') ?: 'Mucho GDPS',
                    'public_url' => $this->env('MUCHO_ACCOUNT_URL'),
                    'gd_versions' => $this->env('MUCHO_GD_VERSIONS') ?: 'all',
                    'transport' => $this->env('MUCHO_TRANSPORT_MODE') ?: 'direct',
                    'cache_driver' => $this->env('MUCHO_CACHE_DRIVER') ?: 'database',
                    'database_backup_sha256' => (string)$backup['sha256'],
                    'security_note' => 'Secrets, .env values, SSH credentials and runtime tokens are intentionally excluded.',
                ];

                $zip->addFromString(
                    'configuration/server-info.json',
                    json_encode(
                        $serverInfo,
                        JSON_PRETTY_PRINT |
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES |
                        JSON_THROW_ON_ERROR
                    )
                );

                $zip->addFromString(
                    'README.txt',
                    $this->readme($serverInfo)
                );
            } finally {
                $zip->close();
            }

            $size = filesize($zipPath);
            $sha256 = hash_file('sha256', $zipPath);

            if (
                $size === false ||
                $size < 1024 ||
                !is_string($sha256) ||
                !preg_match('/^[a-f0-9]{64}$/', $sha256)
            ) {
                @unlink($zipPath);
                throw new RuntimeException('Generated GDPS export archive is invalid.');
            }

            if (
                file_put_contents(
                    $shaPath,
                    $sha256 . '  ' . basename($zipPath) . PHP_EOL,
                    LOCK_EX
                ) === false
            ) {
                @unlink($zipPath);
                throw new RuntimeException('Unable to write GDPS export checksum.');
            }

            return [
                'file' => $zipPath,
                'sha256' => $sha256,
                'sha256_file' => $shaPath,
                'size' => (int)$size,
                'database_backup' => $backup,
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function migrationStatus(): string
    {
        try {
            ob_start();
            (new Migrator(
                $this->pdo,
                rtrim($this->rootDir, '/\\') . '/database/migrations'
            ))->status();
            $output = (string)ob_get_clean();
            return $output !== '' ? $output : "No migration status output.\n";
        } catch (Throwable $e) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            return "Migration status unavailable: " . $e->getMessage() . "\n";
        }
    }

    private function env(string $key): string
    {
        try {
            return trim((string)Environment::get($key, ''));
        } catch (Throwable) {
            $value = getenv($key);
            return $value === false ? '' : trim((string)$value);
        }
    }

    private function version(): string
    {
        $path = rtrim($this->rootDir, '/\\') . '/VERSION';
        return is_file($path)
            ? trim((string)@file_get_contents($path))
            : 'unknown';
    }

    private function readme(array $info): string
    {
        return implode(PHP_EOL, [
            'MuchoCore GDPS Export',
            '======================',
            '',
            'Format: ' . $info['format'],
            'Created: ' . $info['created_at'],
            'Core: ' . $info['core_version'],
            'Server: ' . $info['server_name'],
            'Public URL: ' . $info['public_url'],
            '',
            'Contents',
            '--------',
            'database/  Verified compressed database backup and checksum',
            'clients/   Published client manifest when available',
            'configuration/  Safe, non-secret server information',
            'migrations/  Migration state captured at export time',
            '',
            'Excluded',
            '--------',
            '.env',
            '.secrets/',
            'SSH credentials',
            'deployment tokens',
            'runtime secrets',
            '',
            'Recovery',
            '--------',
            'Install a compatible MuchoCore release on the destination VPS.',
            'Restore the verified database backup using the documented restore workflow.',
            'Run pending schema migrations before serving clients.',
            'Rebuild clients for the destination public URL.',
            '',
        ]) . PHP_EOL;
    }
}
