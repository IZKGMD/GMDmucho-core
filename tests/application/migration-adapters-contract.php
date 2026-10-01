<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$files = [
    'src/Migration/MigrationDatabaseAdapterInterface.php',
    'src/Migration/MigrationDatabaseAdapterRegistry.php',
    'src/Migration/CvoltonMigrationAdapter.php',
    'src/Migration/MigrationLevelDataAdapterInterface.php',
    'src/Migration/MigrationLevelDataAdapterRegistry.php',
    'src/Migration/GalaxxyFilesystemLevelDataAdapter.php',
];

foreach ($files as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('Migration adapter file is missing: ' . $relative);
    }
}

$databaseInterface = file_get_contents(
    $root . '/src/Migration/MigrationDatabaseAdapterInterface.php'
);
$databaseRegistry = file_get_contents(
    $root . '/src/Migration/MigrationDatabaseAdapterRegistry.php'
);
$cvolton = file_get_contents(
    $root . '/src/Migration/CvoltonMigrationAdapter.php'
);
$levelInterface = file_get_contents(
    $root . '/src/Migration/MigrationLevelDataAdapterInterface.php'
);
$levelRegistry = file_get_contents(
    $root . '/src/Migration/MigrationLevelDataAdapterRegistry.php'
);
$galaxxy = file_get_contents(
    $root . '/src/Migration/GalaxxyFilesystemLevelDataAdapter.php'
);
$sqlService = file_get_contents(
    $root . '/src/Migration/SqlDumpMigrationService.php'
);

foreach ([
    'public function key(): string',
    'public function label(): string',
    'public function supports(array $inspection): bool',
    'public function preflight(PDO $source): array',
    'public function apply(PDO $source): array',
] as $needle) {
    if (strpos((string)$databaseInterface, $needle) === false) {
        throw new RuntimeException('Database adapter interface is incomplete: ' . $needle);
    }
}

foreach ([
    'CvoltonMigrationAdapter::class',
    'public function resolve(',
] as $needle) {
    if (strpos((string)$databaseRegistry, $needle) === false) {
        throw new RuntimeException('Database adapter registry is missing: ' . $needle);
    }
}

foreach ([
    "return 'cvolton';",
    "return ($inspection['engine'] ?? 'unknown') === $this->key();",
    'CvoltonDatabaseImporter',
] as $needle) {
    if (strpos((string)$cvolton, $needle) === false) {
        throw new RuntimeException('Cvolton adapter contract missing: ' . $needle);
    }
}

foreach ([
    'public function key(): string',
    'public function supportsUpload(array $upload): bool',
    'public function stageUpload(',
    'public function hydrate(',
    'public function cleanup(',
] as $needle) {
    if (strpos((string)$levelInterface, $needle) === false) {
        throw new RuntimeException('Level-data adapter interface is incomplete: ' . $needle);
    }
}

foreach ([
    'GalaxxyFilesystemLevelDataAdapter::class',
    'public function resolveUpload(',
    'public function instances(): array',
] as $needle) {
    if (strpos((string)$levelRegistry, $needle) === false) {
        throw new RuntimeException('Level-data adapter registry is missing: ' . $needle);
    }
}

foreach ([
    "return 'galaxxy-filesystem';",
    'GalaxxyLevelDataArchiveService',
    'if (!is_dir(',
] as $needle) {
    if (strpos((string)$galaxxy, $needle) === false) {
        throw new RuntimeException('Galaxxy filesystem adapter contract missing: ' . $needle);
    }
}

foreach ([
    'MigrationDatabaseAdapterRegistry',
    'MigrationLevelDataAdapterRegistry',
    '->resolve(',
    '->resolveUpload(',
    '->instances()',
    'databaseAdapter->apply',
    'hydrateExternalLevelData',
] as $needle) {
    if (strpos((string)$sqlService, $needle) === false) {
        throw new RuntimeException('SQL migration service is not adapter-driven: ' . $needle);
    }
}

if (strpos((string)$sqlService, 'new CvoltonDatabaseImporter') !== false) {
    throw new RuntimeException(
        'SQL migration service must not instantiate database adapters directly.'
    );
}

if (strpos((string)$sqlService, 'new GalaxxyLevelDataArchiveService') !== false) {
    throw new RuntimeException(
        'SQL migration service must not instantiate level-data adapters directly.'
    );
}

echo "migration-adapters-contract: OK\n";
