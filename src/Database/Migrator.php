<?php

declare(strict_types=1);

namespace MuchoCore\Database;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationPath,
    ) {
        $this->createMigrationTable();
    }

    private function createMigrationTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(255) NOT NULL PRIMARY KEY,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function migrate(): void
    {
        foreach ($this->migrationFiles() as $file) {
            $version = basename($file, '.php');

            if ($this->isApplied($version)) {
                echo "[SKIP] {$version}\n";
                continue;
            }

            echo "[RUN ] {$version}\n";

            $migration = require $file;
            $this->applyMigration($migration, $version);

            $stmt = $this->pdo->prepare(
                'INSERT INTO schema_migrations (version)
                 VALUES (:version)'
            );

            $stmt->execute([
                'version' => $version,
            ]);

            echo "[ OK ] {$version}\n";
        }
    }

    public function status(): void
    {
        foreach ($this->migrationFiles() as $file) {
            $version = basename($file, '.php');

            echo sprintf(
                "%-10s %s\n",
                $this->isApplied($version) ? '[APPLIED]' : '[PENDING]',
                $version
            );
        }
    }

    private function applyMigration(
        mixed $migration,
        string $version
    ): void {
        if ($migration instanceof Migration) {
            $migration->up($this->pdo);
            return;
        }

        if (is_callable($migration)) {
            $migration($this->pdo);
            return;
        }

        if (is_array($migration)) {
            foreach ($migration as $sql) {
                if (!is_string($sql) || trim($sql) === '') {
                    continue;
                }

                $this->pdo->exec($sql);
            }

            return;
        }

        throw new RuntimeException(
            "Migration {$version} must return SQL array, callable, or Migration object"
        );
    }

    private function migrationFiles(): array
    {
        $files = glob($this->migrationPath . '/*.php') ?: [];
        sort($files, SORT_STRING);

        return array_values($files);
    }

    private function isApplied(string $version): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM schema_migrations
             WHERE version = :version
             LIMIT 1'
        );

        $stmt->execute([
            'version' => $version,
        ]);

        return $stmt->fetchColumn() !== false;
    }
}
