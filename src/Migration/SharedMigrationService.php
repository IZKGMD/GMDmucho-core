<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use PDO;
use RuntimeException;
use Throwable;
use MuchoCore\Backup\DatabaseBackupService;
use MuchoCore\Database\Migrator;

final class SharedMigrationService
{
    private const LOCK_FILE = 'migration-center.lock';

    public function __construct(
        private readonly PDO $target,
        private readonly string $root,
        private readonly string $backupDirectory,
    ) {
    }

    public static function connectSource(
        string $host,
        int $port,
        string $database,
        string $user,
        string $password,
    ): PDO {
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $host)) {
            throw new RuntimeException(
                'Invalid source host. Enter the MySQL/MariaDB host, not the GDPS website URL.'
            );
        }

        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid source port.');
        }

        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $database)) {
            throw new RuntimeException('Invalid source database name.');
        }

        if ($user === '' || strlen($user) > 128) {
            throw new RuntimeException('Invalid source database user.');
        }

        return new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY',
            ]
        );
    }

    public function preview(PDO $source): array
    {
        $this->inspection($source);
        $importer = new CvoltonDatabaseImporter($this->target);

        return [
            'inspection' => (new SourceDetector())->inspect($source),
            'preflight' => $importer->preflight($source),
        ];
    }

    public function apply(PDO $source): array
    {
        $lockPath = rtrim($this->root, '/\\') . '/storage/' . self::LOCK_FILE;
        $lock = fopen($lockPath, 'c');

        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException(
                'Another migration is already running on this shared-hosting installation.'
            );
        }

        try {
            $inspection = $this->inspection($source);
            $importer = new CvoltonDatabaseImporter($this->target);
            $preflight = $importer->preflight($source);

            $backup = (new DatabaseBackupService(
                $this->target,
                $this->backupDirectory
            ))->create('muchocore-before-migration');

            $backupMtime = filemtime($backup['file']);

            if ($backupMtime === false || $backupMtime < time() - 10) {
                throw new RuntimeException(
                    'The target database backup is not fresh enough; migration was not started.'
                );
            }

            (new Migrator(
                $this->target,
                $this->root . '/database/migrations'
            ))->migrate();

            $this->requireTargetMaps();

            $this->target->beginTransaction();

            try {
                $stats = $importer->apply($source);
                $this->target->commit();
            } catch (Throwable $e) {
                if ($this->target->inTransaction()) {
                    $this->target->rollBack();
                }
                throw $e;
            }

            return [
                'inspection' => $inspection,
                'preflight' => $preflight,
                'backup' => $backup,
                'stats' => $stats,
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function inspection(PDO $source): array
    {
        $inspection = (new SourceDetector())->inspect($source);

        if (($inspection['engine'] ?? 'unknown') !== 'cvolton') {
            throw new RuntimeException(
                'Unsupported source schema. Detected tables: ' .
                (implode(', ', $inspection['present_tables'] ?? []) ?: 'none')
            );
        }

        return $inspection;
    }

    private function requireTargetMaps(): void
    {
        foreach ([
            'mucho_cvolton_account_map',
            'mucho_cvolton_level_map',
        ] as $table) {
            $statement = $this->target->prepare(
                'SELECT 1
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = :table
                 LIMIT 1'
            );
            $statement->execute(['table' => $table]);

            if ($statement->fetchColumn() === false) {
                throw new RuntimeException(
                    'MuchoCore migration support tables are missing: ' . $table
                );
            }
        }
    }
}
