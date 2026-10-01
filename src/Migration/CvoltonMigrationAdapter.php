<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use PDO;

final class CvoltonMigrationAdapter implements MigrationDatabaseAdapterInterface
{
    public function __construct(
        private readonly PDO $target,
        private readonly string $sourcePrefix = ''
    ) {
        if (
            $this->sourcePrefix !== '' &&
            !preg_match('/^mci_[a-f0-9]{16}_$/', $this->sourcePrefix)
        ) {
            throw new \InvalidArgumentException('Invalid source table prefix.');
        }
    }

    public function key(): string
    {
        return 'cvolton';
    }

    public function label(): string
    {
        return 'Cvolton-compatible database';
    }

    public function supports(array $inspection): bool
    {
        return ($inspection['engine'] ?? 'unknown') === $this->key();
    }

    public function preflight(PDO $source): array
    {
        return $this->importer()->preflight($source);
    }

    public function apply(PDO $source): array
    {
        return $this->importer()->apply($source);
    }

    private function importer(): CvoltonDatabaseImporter
    {
        return new CvoltonDatabaseImporter($this->target, $this->sourcePrefix);
    }
}
