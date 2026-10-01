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
    private const MAX_MUSIC_FILE_BYTES = 33554432;
    private const MAX_MUSIC_TOTAL_BYTES = 134217728;

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
        array $sourceSongIds,
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

        $music = $this->stageMusic(
            $upload,
            $sourceSongIds,
            $stagingPrefix
        );

        return [
            'level_data' => $levelData,
            'cloud_saves' => $cloudSaves,
            'music' => $music,
        ];
    }

    public function hydrate(
        PDO $target,
        string $stagingPrefix,
        array $sourceLevelIds,
        array $sourceAccountIds,
        array $sourceSongIds
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

    /**
     * @return array{music_files_published:int,music_bytes_published:int,song_urls_rewritten:int}
     */
    public function publish(
        PDO $target,
        string $stagingPrefix,
        array $sourceSongIds
    ): array {
        $directory = $this->archiveDirectory($stagingPrefix) . '/music';

        if (!is_dir($directory)) {
            return [
                'music_files_published' => 0,
                'music_bytes_published' => 0,
                'song_urls_rewritten' => 0,
            ];
        }

        $destinationRoot = rtrim($this->root, '/\\') . '/storage/music-public';
        if (!is_dir($destinationRoot)
            && !mkdir($destinationRoot, 0770, true)
            && !is_dir($destinationRoot)) {
            throw new RuntimeException('Unable to create MuchoCore music storage.');
        }

        $legacyDirectory = $destinationRoot . '/legacy/' . trim($stagingPrefix, '_');
        $songDirectory = $legacyDirectory . '/songs';

        if (!is_dir($songDirectory)
            && !mkdir($songDirectory, 0770, true)
            && !is_dir($songDirectory)) {
            throw new RuntimeException('Unable to create migrated music storage.');
        }

        $published = 0;
        $bytes = 0;
        $rewritten = 0;

        $songUrlBase = trim(
            (string)(
                getenv('MUCHO_PUBLIC_URL')
                ?: getenv('MUCHO_ACCOUNT_URL')
                ?: ''
            )
        );

        $songIds = array_fill_keys(
            array_values(
                array_unique(
                    array_filter(
                        array_map('intval', $sourceSongIds),
                        static fn(int $id): bool => $id > 0
                    )
                )
            ),
            true
        );

        $songFiles = glob($directory . '/songs/*.mp3') ?: [];

        if ($songFiles !== [] && !preg_match(
            '~^https://[A-Za-z0-9.-]+(?::\d+)?$~',
            $songUrlBase
        )) {
            throw new RuntimeException(
                'MUCHO_PUBLIC_URL must be configured before migrating local song files.'
            );
        }

        if ($songUrlBase !== '') {
            $songUrlBase = rtrim($songUrlBase, '/');
        }

        foreach ($songFiles as $sourcePath) {
            $sourceId = $this->songIdFromStagedPath($sourcePath);

            if ($sourceId === null) {
                continue;
            }

            $fileSize = filesize($sourcePath);
            if ($fileSize === false || $fileSize < 1 || $fileSize > self::MAX_MUSIC_FILE_BYTES) {
                throw new RuntimeException(
                    'Migrated song file is missing or exceeds the 32 MB limit.'
                );
            }

            $destinationName = (string)$sourceId . '.mp3';
            $destination = $songDirectory . '/' . $destinationName;

            if (!copy($sourcePath, $destination)) {
                throw new RuntimeException(
                    'Unable to publish migrated song ' . $sourceId . '.'
                );
            }

            @chmod($destination, 0640);

            $published++;
            $bytes += (int)$fileSize;

            if (isset($songIds[$sourceId])) {
                $url = ($songUrlBase !== ''
                    ? $songUrlBase
                    : ''
                ) . '/music/legacy/' .
                    trim($stagingPrefix, '_') .
                    '/songs/' .
                    rawurlencode($destinationName);

                $q = $target->prepare(
                    'UPDATE songs
                     SET download_url=:url
                     WHERE id=:id'
                );
                $q->execute([
                    'url' => $url,
                    'id' => $sourceId,
                ]);

                if ($q->rowCount() > 0) {
                    $rewritten++;
                }
            }
        }

        $staticNames = [
            'gdps.dat',
            'gdps.txt',
            'standalone.dat',
            's1.dat',
            's1.txt',
            's4.dat',
            's4.txt',
            'ids.json',
        ];

        foreach ($staticNames as $name) {
            $sourcePath = $directory . '/' . $name;

            if (!is_file($sourcePath)) {
                continue;
            }

            $destination = $legacyDirectory . '/' . $name;
            $size = filesize($sourcePath);

            if ($size === false || $size < 1 || $size > self::MAX_MUSIC_FILE_BYTES) {
                throw new RuntimeException(
                    'Migrated music library file is invalid or too large: ' . $name
                );
            }

            if (!copy($sourcePath, $destination)) {
                throw new RuntimeException(
                    'Unable to publish migrated music library file ' . $name . '.'
                );
            }

            @chmod($destination, 0640);
            $published++;
            $bytes += (int)$size;
        }

        return [
            'music_files_published' => $published,
            'music_bytes_published' => $bytes,
            'song_urls_rewritten' => $rewritten,
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
    private function stageMusic(
        array $upload,
        array $sourceSongIds,
        string $stagingPrefix
    ): array {
        $tmp = (string)($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('The Galaxxy server archive is not available.');
        }

        $directory = $this->archiveDirectory($stagingPrefix) . '/music';
        $songDirectory = $directory . '/songs';

        if (!is_dir($songDirectory)
            && !mkdir($songDirectory, 0700, true)
            && !is_dir($songDirectory)) {
            throw new RuntimeException('Unable to create music staging storage.');
        }

        $allowedSongs = array_fill_keys(
            array_values(
                array_unique(
                    array_filter(
                        array_map('intval', $sourceSongIds),
                        static fn(int $id): bool => $id > 0
                    )
                )
            ),
            true
        );

        $staticNames = [
            'gdps.dat',
            'gdps.txt',
            'standalone.dat',
            's1.dat',
            's1.txt',
            's4.dat',
            's4.txt',
            'ids.json',
        ];

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('Unable to open the Galaxxy server archive.');
        }

        $storedFiles = 0;
        $matchedFiles = 0;
        $storedBytes = 0;
        $matchedBytes = 0;

        try {
            for ($i = 0, $count = $zip->numFiles; $i < $count; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    continue;
                }

                $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
                $songId = $this->songIdFromArchivePath($name);

                $static = null;
                foreach ($staticNames as $candidate) {
                    if (
                        $name === 'galaxxygdps/public_html/music/' . $candidate ||
                        $name === 'public_html/music/' . $candidate ||
                        $name === 'music/' . $candidate
                    ) {
                        $static = $candidate;
                        break;
                    }
                }

                if ($songId === null && $static === null) {
                    continue;
                }

                $fileBytes = (int)($stat['size'] ?? -1);
                if ($fileBytes < 1 || $fileBytes > self::MAX_MUSIC_FILE_BYTES) {
                    throw new RuntimeException(
                        'Migrated music file is missing or exceeds the 32 MB limit.'
                    );
                }

                if ($storedBytes + $fileBytes > self::MAX_MUSIC_TOTAL_BYTES) {
                    throw new RuntimeException(
                        'Migrated music assets exceed the 128 MB total limit.'
                    );
                }

                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new RuntimeException('Unable to read migrated music asset.');
                }

                $relative = $songId !== null
                    ? 'songs/' . $songId . '.mp3'
                    : $static;

                $target = $directory . '/' . $relative;
                $parent = dirname($target);

                if (!is_dir($parent)
                    && !mkdir($parent, 0700, true)
                    && !is_dir($parent)) {
                    fclose($stream);
                    throw new RuntimeException('Unable to create music staging directory.');
                }

                $output = fopen($target . '.tmp', 'wb');
                if ($output === false) {
                    fclose($stream);
                    throw new RuntimeException('Unable to stage migrated music asset.');
                }

                try {
                    $written = 0;

                    while (!feof($stream)) {
                        $chunk = fread($stream, 1024 * 1024);
                        if ($chunk === false) {
                            throw new RuntimeException('Failed while reading migrated music asset.');
                        }

                        if ($chunk === '') {
                            continue;
                        }

                        $written += strlen($chunk);

                        if ($written > self::MAX_MUSIC_FILE_BYTES) {
                            throw new RuntimeException('Migrated music file exceeds the 32 MB limit.');
                        }

                        $length = strlen($chunk);
                        if (fwrite($output, $chunk) !== $length) {
                            throw new RuntimeException('Failed while staging migrated music asset.');
                        }
                    }
                } finally {
                    fclose($output);
                    fclose($stream);
                }

                if (!rename($target . '.tmp', $target)) {
                    @unlink($target . '.tmp');
                    throw new RuntimeException('Unable to publish staged music asset.');
                }

                @chmod($target, 0600);

                $storedFiles++;
                $storedBytes += $written;

                if ($songId !== null && isset($allowedSongs[$songId])) {
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

    private function songIdFromArchivePath(string $path): ?int
    {
        $match = [];

        if (!preg_match(
            '#(?:^|/)public_html/dashboard/songs/(\\d+)\.mp3$#',
            $path,
            $match
        )) {
            if (!preg_match(
                '#(?:^|/)dashboard/songs/(\\d+)\.mp3$#',
                $path,
                $match
            )) {
                return null;
            }
        }

        $id = (int)($match[1] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function songIdFromStagedPath(string $path): ?int
    {
        $match = [];

        if (!preg_match(
            '#/music/songs/(\\d+)\.mp3$#',
            str_replace('\\', '/', $path),
            $match
        )) {
            return null;
        }

        $id = (int)($match[1] ?? 0);
        return $id > 0 ? $id : null;
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

        if (!is_dir(
            rtrim($this->root, '/\\') .
            '/storage/migration-sql/' .
            trim($stagingPrefix, '_') .
            '/levels'
        )) {
            return [
                'hydrated' => 0,
                'missing' => 0,
                'bytes' => 0,
            ];
        }

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
