<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use MuchoCore\CloudSave\CloudSaveRepository;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

final class GalaxxyServerArchiveAdapter implements MigrationServerArchiveAdapterInterface
{
    private const MAX_ARCHIVE_BYTES = 268435456;
    private const MAX_UNCOMPRESSED_BYTES = 536870912;
    private const MAX_ACCOUNT_SAVE_BYTES = 33554432;
    private const MAX_ACCOUNT_FILES = 1000;

    public function __construct(
        private readonly string $root
    ) {}

    public function key(): string
    {
        return 'galaxxy-server';
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
        array $sourceAccountIds,
        string $stagingPrefix
    ): array {
        $upload['_staging_prefix'] = $stagingPrefix;

        $levelData = (new GalaxxyLevelDataArchiveService($this->root))
            ->stageUpload($upload, $sourceLevelIds);

        $cloudSaves = $this->stageAccountSaves(
            $upload,
            $sourceAccountIds,
            $stagingPrefix
        );

        return [
            'level_data' => $levelData,
            'cloud_saves' => $cloudSaves,
        ];
    }

    public function hydrate(
        PDO $target,
        string $stagingPrefix,
        array $sourceLevelIds,
        array $sourceAccountIds
    ): array {
        $levels = $this->hydrateLevelData(
            $target,
            $stagingPrefix,
            $sourceLevelIds
        );

        $cloudSaves = $this->hydrateCloudSaves(
            $target,
            $stagingPrefix,
            $sourceAccountIds
        );

        return [
            'level_data_hydrated' => $levels['hydrated'],
            'level_data_missing' => $levels['missing'],
            'level_data_bytes' => $levels['bytes'],
            'cloud_saves_imported' => $cloudSaves['imported'],
            'cloud_saves_missing' => $cloudSaves['missing'],
            'cloud_saves_bytes' => $cloudSaves['bytes'],
        ];
    }

    public function cleanup(string $stagingPrefix): void
    {
        (new GalaxxyLevelDataArchiveService($this->root))->cleanup(
            $stagingPrefix
        );
    }

    /**
     * @return array{stored_files:int,matched_files:int,stored_bytes:int,matched_bytes:int}
     */
    private function stageAccountSaves(
        array $upload,
        array $sourceAccountIds,
        string $stagingPrefix
    ): array {
        $tmp = (string)($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('The Galaxxy server archive is not available.');
        }

        $size = (int)($upload['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('Galaxxy server archive must be between 1 byte and 256 MB.');
        }

        $allowed = array_fill_keys(
            array_values(
                array_unique(
                    array_filter(
                        array_map('intval', $sourceAccountIds),
                        static fn(int $id): bool => $id > 0
                    )
                )
            ),
            true
        );

        $directory = $this->archiveDirectory($stagingPrefix);
        $accountDirectory = $directory . '/accounts';

        if (!is_dir($accountDirectory)
            && !mkdir($accountDirectory, 0700, true)
            && !is_dir($accountDirectory)) {
            throw new RuntimeException(
                'Unable to create legacy cloud-save staging storage.'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('Unable to open the Galaxxy server archive.');
        }

        $storedFiles = 0;
        $matchedFiles = 0;
        $storedBytes = 0;
        $matchedBytes = 0;
        $memberCount = $zip->numFiles;

        try {
            $accountFiles = 0;

            for ($i = 0; $i < $memberCount; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    continue;
                }

                $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
                $accountId = $this->accountIdFromPath($name);

                if ($accountId === null) {
                    continue;
                }

                $accountFiles++;
                if ($accountFiles > self::MAX_ACCOUNT_FILES) {
                    throw new RuntimeException(
                        'Galaxxy server archive contains too many account save files.'
                    );
                }

                $fileBytes = (int)($stat['size'] ?? -1);
                if ($fileBytes < 1 || $fileBytes > self::MAX_ACCOUNT_SAVE_BYTES) {
                    throw new RuntimeException(
                        'A legacy account save file is missing or exceeds the 32 MB limit.'
                    );
                }

                if ($storedBytes + $fileBytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException(
                        'Galaxxy server archive exceeds the uncompressed size limit.'
                    );
                }

                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new RuntimeException(
                        'Unable to read legacy account save ' . $accountId . '.'
                    );
                }

                $target = $accountDirectory . '/' . $accountId;
                $output = fopen($target . '.tmp', 'wb');

                if ($output === false) {
                    fclose($stream);
                    throw new RuntimeException(
                        'Unable to stage legacy account save ' . $accountId . '.'
                    );
                }

                try {
                    $written = 0;

                    while (!feof($stream)) {
                        $chunk = fread($stream, 1024 * 1024);
                        if ($chunk === false) {
                            throw new RuntimeException(
                                'Failed while reading legacy account save ' . $accountId . '.'
                            );
                        }

                        if ($chunk === '') {
                            continue;
                        }

                        $written += strlen($chunk);

                        if ($written > self::MAX_ACCOUNT_SAVE_BYTES) {
                            throw new RuntimeException(
                                'Legacy account save ' . $accountId . ' exceeds the 32 MB limit.'
                            );
                        }

                        $length = strlen($chunk);
                        if (fwrite($output, $chunk) !== $length) {
                            throw new RuntimeException(
                                'Failed while staging legacy account save ' . $accountId . '.'
                            );
                        }
                    }
                } finally {
                    fclose($output);
                    fclose($stream);
                }

                if (!rename($target . '.tmp', $target)) {
                    @unlink($target . '.tmp');
                    throw new RuntimeException(
                        'Unable to publish legacy account save ' . $accountId . '.'
                    );
                }

                @chmod($target, 0600);

                $storedFiles++;
                $storedBytes += $written;

                if (isset($allowed[$accountId])) {
                    $matchedFiles++;
                    $matchedBytes += $written;
                }
            }
        } finally {
            $zip->close();
        }

        return [
            'stored_files' => $storedFiles,
            'matched_files' => $matchedFiles,
            'stored_bytes' => $storedBytes,
            'matched_bytes' => $matchedBytes,
        ];
    }

    /**
     * @return array{hydrated:int,missing:int,bytes:int}
     */
    private function hydrateLevelData(
        PDO $target,
        string $stagingPrefix,
        array $sourceLevelIds
    ): array {
        $archive = new GalaxxyLevelDataArchiveService($this->root);

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

            $data = $archive->levelData(
                $stagingPrefix,
                $sourceLevelId
            );

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

    /**
     * @return array{imported:int,missing:int,bytes:int}
     */
    private function hydrateCloudSaves(
        PDO $target,
        string $stagingPrefix,
        array $sourceAccountIds
    ): array {
        $directory = $this->archiveDirectory($stagingPrefix) . '/accounts';

        if (!is_dir($directory)) {
            return [
                'imported' => 0,
                'missing' => 0,
                'bytes' => 0,
            ];
        }

        $mapped = $target->prepare(
            'SELECT target_id FROM mucho_cvolton_account_map
             WHERE source_id=:source LIMIT 1'
        );

        $repository = new CloudSaveRepository($target);
        $imported = 0;
        $missing = 0;
        $bytes = 0;

        foreach (array_values(array_unique(array_map('intval', $sourceAccountIds))) as $sourceAccountId) {
            if ($sourceAccountId <= 0) {
                continue;
            }

            $path = $directory . '/' . $sourceAccountId;

            if (!is_file($path)) {
                $missing++;
                continue;
            }

            $mapped->execute(['source' => $sourceAccountId]);
            $targetId = $mapped->fetchColumn();

            if ($targetId === false) {
                $missing++;
                continue;
            }

            $saveData = (string)file_get_contents($path);
            if ($saveData === '' || strlen($saveData) > self::MAX_ACCOUNT_SAVE_BYTES) {
                throw new RuntimeException(
                    'Legacy cloud save ' . $sourceAccountId . ' is invalid or too large.'
                );
            }

            try {
                $repository->save((int)$targetId, $saveData);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Unable to import legacy cloud save for source account ' .
                    $sourceAccountId . '.',
                    0,
                    $e
                );
            }

            $imported++;
            $bytes += strlen($saveData);
        }

        return [
            'imported' => $imported,
            'missing' => $missing,
            'bytes' => $bytes,
        ];
    }

    private function accountIdFromPath(string $path): ?int
    {
        $match = [];

        if (!preg_match(
            '#(?:^|/)public_html/data/accounts/(\d+)$#',
            $path,
            $match
        )) {
            if (!preg_match(
                '#(?:^|/)data/accounts/(\d+)$#',
                $path,
                $match
            )) {
                return null;
            }
        }

        $id = (int)($match[1] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function archiveDirectory(string $stagingPrefix): string
    {
        if (!preg_match('/^mci_[a-f0-9]{16}_$/', $stagingPrefix)) {
            throw new RuntimeException(
                'Invalid SQL migration staging reference.'
            );
        }

        return rtrim($this->root, '/\\') .
            '/storage/migration-sql/' .
            trim($stagingPrefix, '_');
    }
}
