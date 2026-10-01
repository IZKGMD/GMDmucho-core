<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use PDO;

interface MigrationLevelDataAdapterInterface
{
    public function key(): string;

    /**
     * @param array<string,mixed> $upload
     */
    public function supportsUpload(array $upload): bool;

    /**
     * @param list<int> $sourceLevelIds
     * @return array{stored_files:int,matched_files:int,stored_bytes:int,matched_bytes:int}
     */
    public function stageUpload(
        array $upload,
        array $sourceLevelIds,
        string $stagingPrefix
    ): array;

    /**
     * @return array{hydrated:int,missing:int,bytes:int}
     */
    public function hydrate(
        PDO $target,
        string $stagingPrefix,
        array $sourceLevelIds
    ): array;

    public function cleanup(string $stagingPrefix): void;
}
