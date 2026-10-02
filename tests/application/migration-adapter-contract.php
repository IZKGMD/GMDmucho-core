<?php

declare(strict_types=1);

function assertMigrationAdapter(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$contract = (string)file_get_contents(
    __DIR__ . '/../../src/Migration/MigrationAdapterInterface.php'
);
$adapter = (string)file_get_contents(
    __DIR__ . '/../../src/Migration/CvoltonMigrationAdapter.php'
);
$registry = (string)file_get_contents(
    __DIR__ . '/../../src/Migration/MigrationAdapterRegistry.php'
);
$service = (string)file_get_contents(
    __DIR__ . '/../../src/Migration/SharedMigrationService.php'
);
$cli = (string)file_get_contents(
    __DIR__ . '/../../bin/mucho-migrate.php'
);

assertMigrationAdapter(
    str_contains($contract, 'interface MigrationAdapterInterface') &&
    str_contains($contract, 'function id(): string') &&
    str_contains($contract, 'function supports(array $inspection): bool') &&
    str_contains($contract, 'function preflight(PDO $source): array') &&
    str_contains($contract, 'function apply(PDO $source): array'),
    'migration adapter contract exposes stable detection and execution methods'
);

assertMigrationAdapter(
    str_contains($adapter, "return 'cvolton';") &&
    str_contains($adapter, "($inspection['engine'] ?? '') === $this->id()") &&
    str_contains($adapter, 'new CvoltonDatabaseImporter($this->target)'),
    'Cvolton implementation is isolated behind the adapter contract'
);

assertMigrationAdapter(
    str_contains($registry, 'class MigrationAdapterRegistry') &&
    str_contains($registry, 'new CvoltonMigrationAdapter($target)') &&
    str_contains($registry, '$adapter->supports($inspection)') &&
    str_contains($registry, "throw new RuntimeException("),
    'adapter registry selects a matching adapter and rejects unsupported schemas'
);

assertMigrationAdapter(
    str_contains($service, 'MigrationAdapterRegistry') &&
    str_contains($service, '$adapter->preflight($source)') &&
    str_contains($service, '$adapter->apply($source)') &&
    !str_contains($service, 'new CvoltonDatabaseImporter($this->target)'),
    'shared migration service no longer hardcodes the Cvolton importer'
);

assertMigrationAdapter(
    str_contains($cli, 'MigrationAdapterRegistry') &&
    str_contains($cli, '$registry->detect($source)') &&
    str_contains($cli, 'Adapter:') &&
    str_contains($cli, '$adapter->apply($source)'),
    'CLI migration flow reports and executes the selected adapter'
);

echo "MUCHOCORE_MIGRATION_ADAPTER_CONTRACT_OK\n";
