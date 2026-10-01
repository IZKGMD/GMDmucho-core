<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use PDO;

interface MigrationServerArchiveAdapterInterface
{
    public function key(): string;

    public function supportsUpload(array $upload): bool;

    /**
     * @param list<int> $sourceLevelIds
     * @param list<int> $sourceAccountIds
     * @return array{
     *   level_data:array{stored_files:int,matched_files:int,stored_bytes:int,matched_bytes:int},
     *   cloud_saves:array{stored_files:int,matched_files:int,stored_bytes:int,matched_bytes:int}
     * }
     */
    public function stageUpload(
        array $upload,
        array $sourceLevelIds,
        array $sourceAccountIds,
        string $stagingPrefix
    ): array;

    /**
     * @param list<int> $sourceLevelIds
     * @param list<int> $sourceAccountIds
     * @return array{
     *   level_data_hydrated:int,
     *   level_data_missing:int,
     *   level_data_bytes:int,
     *   cloud_saves_imported:int,
     *   cloud_saves_missing:int,
     *   cloud_saves_bytes:int
     * }
     */
    public function hydrate(
        PDO $target,
        string $stagingPrefix,
        array $sourceLevelIds,
        array $sourceAccountIds
    ): array;

    public function cleanup(string $stagingPrefix): void;
}
