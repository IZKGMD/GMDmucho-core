<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use PDO;

final class GalaxxyFilesystemLevelDataAdapter implements MigrationLevelDataAdapterInterface
{
    public function __construct(
        private readonly string $root = ''
    ) {}

    public function key(): string
    {
        return 'galaxxy-filesystem';
    }

    public function supportsUpload(array $upload): bool
    {
        return str_ends_with(
            strtolower((string)($upload['name'] ?? '')),
            '.zip'
        );
    }

    public function stageUpload(
        array $upload,
        array $sourceLevelIds,
        string $stagingPrefix
    ): array {
        $upload['_staging_prefix'] = $stagingPrefix;

        return $this->service()->stageUpload($upload, $sourceLevelIds);
    }

    public function hydrate(
        PDO $target,
        string $stagingPrefix,
        array $sourceLevelIds
    ): array {
        $archive = $this->service();
        $mapped = $target->prepare(
            'SELECT target_id FROM mucho_cvolton_level_map
             WHERE source_id=:source LIMIT 1'
        );
        $update = $target->prepare(
            'UPDATE levels SET level_data=:data WHERE level_id=:target'
        );

        $hydrated = 0;
        $missing = 0;
        $bytes = 0;

        foreach ($sourceLevelIds as $sourceLevelId) {
            $sourceLevelId = (int)$sourceLevelId;
            if ($sourceLevelId <= 0) {
                continue;
            }

            $data = $archive->levelData($stagingPrefix, $sourceLevelId);
            if ($data === null) {
                $missing++;
                continue;
            }

            $mapped->execute(['source' => $sourceLevelId]);
            $targetId = $mapped->fetchColumn();

            if ($targetId === false) {
                $missing++;
                continue;
            }

            $update->execute([
                'data' => $data,
                'target' => (int)$targetId,
            ]);

            $hydrated++;
            $bytes += strlen($data);
        }

        return [
            'hydrated' => $hydrated,
            'missing' => $missing,
            'bytes' => $bytes,
        ];
    }

    public function cleanup(string $stagingPrefix): void
    {
        $this->service()->cleanup($stagingPrefix);
    }

    private function service(): GalaxxyLevelDataArchiveService
    {
        if ($this->root === '') {
            throw new \LogicException(
                'Galaxxy filesystem adapter requires the MuchoCore root directory.'
            );
        }

        return new GalaxxyLevelDataArchiveService($this->root);
    }
}
