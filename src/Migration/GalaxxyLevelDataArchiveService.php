<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use RuntimeException;
use ZipArchive;

final class GalaxxyLevelDataArchiveService
{
    private const MAX_ARCHIVE_BYTES = 268435456;
    private const MAX_UNCOMPRESSED_BYTES = 536870912;
    private const MAX_FILE_BYTES = 16777216;
    private const MAX_FILES = 5000;

    public function __construct(
        private readonly string $root
    ) {}

    /**
     * @param array<string,mixed> $upload
     * @param list<int> $sourceLevelIds
     * @return array{
     *   stored_files:int,
     *   matched_files:int,
     *   stored_bytes:int,
     *   matched_bytes:int
     * }
     */
    public function stageUpload(array $upload, array $sourceLevelIds): array
    {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadError($error));
        }

        $tmp = (string)($upload['tmp_name'] ?? '');
        $size = (int)($upload['size'] ?? 0);

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('The Galaxxy data archive is not available.');
        }

        if ($size < 1 || $size > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('Galaxxy data archive must be between 1 byte and 256 MB.');
        }

        $lower = strtolower((string)($upload['name'] ?? ''));
        if (!str_ends_with($lower, '.zip')) {
            throw new RuntimeException('Only .zip Galaxxy data archives are supported.');
        }

        $prefix = (string)($upload['_staging_prefix'] ?? '');
        if (!preg_match('/^mci_[a-f0-9]{16}_$/', $prefix)) {
            throw new RuntimeException('Invalid SQL migration staging reference.');
        }

        $directory = $this->directory($prefix);
        $levelsDirectory = $directory . '/levels';

        if (!is_dir($levelsDirectory)
            && !mkdir($levelsDirectory, 0700, true)
            && !is_dir($levelsDirectory)) {
            throw new RuntimeException('Unable to create Galaxxy level-data staging storage.');
        }

        $allowed = array_fill_keys(
            array_values(
                array_unique(
                    array_filter(
                        array_map('intval', $sourceLevelIds),
                        static fn(int $id): bool => $id > 0
                    )
                )
            ),
            true
        );

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('Unable to open the Galaxxy data archive.');
        }

        $storedFiles = 0;
        $matchedFiles = 0;
        $storedBytes = 0;
        $matchedBytes = 0;
        $memberCount = $zip->numFiles;

        try {
            if ($memberCount > self::MAX_FILES) {
                throw new RuntimeException(
                    'Galaxxy data archive contains too many files.'
                );
            }

            for ($i = 0; $i < $memberCount; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    continue;
                }

                $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                $levelId = $this->levelIdFromPath($name);
                if ($levelId === null) {
                    continue;
                }

                $fileBytes = (int)($stat['size'] ?? -1);
                if ($fileBytes < 0 || $fileBytes > self::MAX_FILE_BYTES) {
                    throw new RuntimeException(
                        'Galaxxy level-data file is too large.'
                    );
                }

                if ($storedBytes + $fileBytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException(
                        'Galaxxy data archive exceeds the uncompressed size limit.'
                    );
                }

                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new RuntimeException(
                        'Unable to read Galaxxy level-data file ' . $levelId . '.'
                    );
                }

                $target = $levelsDirectory . '/' . $levelId;
                $output = fopen($target . '.tmp', 'wb');
                if ($output === false) {
                    fclose($stream);
                    throw new RuntimeException(
                        'Unable to stage Galaxxy level-data file ' . $levelId . '.'
                    );
                }

                try {
                    $written = 0;

                    while (!feof($stream)) {
                        $chunk = fread($stream, 1024 * 1024);
                        if ($chunk === false) {
                            throw new RuntimeException(
                                'Failed while reading Galaxxy level-data file ' . $levelId . '.'
                            );
                        }

                        if ($chunk === '') {
                            continue;
                        }

                        $written += strlen($chunk);

                        if ($written > self::MAX_FILE_BYTES) {
                            throw new RuntimeException(
                                'Galaxxy level-data file is too large.'
                            );
                        }

                        if (
                            fwrite($output, $chunk) !== strlen($chunk)
                        ) {
                            throw new RuntimeException(
                                'Failed while staging Galaxxy level-data file ' . $levelId . '.'
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
                        'Unable to publish staged Galaxxy level-data file ' . $levelId . '.'
                    );
                }

                @chmod($target, 0600);

                $storedFiles++;
                $storedBytes += $written;

                if (isset($allowed[$levelId])) {
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

    public function levelData(string $prefix, int $sourceLevelId): ?string
    {
        $this->validatePrefix($prefix);

        if ($sourceLevelId <= 0) {
            return null;
        }

        $path = $this->directory($prefix) . '/levels/' . $sourceLevelId;
        if (!is_file($path)) {
            return null;
        }

        $encoded = trim((string)file_get_contents($path));
        if ($encoded === '') {
            return null;
        }

        if (str_starts_with($encoded, 'kS')) {
            return $encoded;
        }

        $normalized = preg_replace('/\\s+/', '', $encoded) ?? '';
        $normalized = strtr($normalized, '-_', '+/');
        $remainder = strlen($normalized) % 4;

        if ($remainder === 1) {
            throw new RuntimeException(
                'Invalid base64 level-data archive entry for level ' . $sourceLevelId . '.'
            );
        }

        if ($remainder !== 0) {
            $normalized .= str_repeat('=', 4 - $remainder);
        }

        $compressed = base64_decode($normalized, true);
        if ($compressed === false) {
            throw new RuntimeException(
                'Invalid base64 level-data archive entry for level ' . $sourceLevelId . '.'
            );
        }

        if (strlen($compressed) >= 2 && $compressed[0] === "\x1f" && $compressed[1] === "\x8b") {
            $decoded = gzdecode($compressed);
            if ($decoded === false) {
                throw new RuntimeException(
                    'Invalid gzip level-data archive entry for level ' . $sourceLevelId . '.'
                );
            }

            $result = $decoded;
        } elseif (
            strlen($compressed) >= 2 &&
            $compressed[0] === "\x78" &&
            in_array($compressed[1], ["\x01", "\x5e", "\x9c", "\xda"], true)
        ) {
            $decoded = zlib_decode($compressed);
            if ($decoded === false) {
                throw new RuntimeException(
                    'Invalid zlib level-data archive entry for level ' . $sourceLevelId . '.'
                );
            }

            $result = $decoded;
        } else {
            $result = $compressed;
        }

        if ($result === '' || !str_starts_with($result, 'kS')) {
            throw new RuntimeException(
                'Decoded level-data archive entry ' . $sourceLevelId . ' is not a valid Geometry Dash level payload.'
            );
        }

        return $result;
    }

    public function cleanup(string $prefix): void
    {
        $this->validatePrefix($prefix);

        $directory = $this->directory($prefix);
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    private function levelIdFromPath(string $path): ?int
    {
        $match = [];

        if (!preg_match(
            '#(?:^|/)public_html/data/levels/(?:deleted/)?(\\d+)$#',
            $path,
            $match
        )) {
            if (!preg_match(
                '#(?:^|/)data/levels/(?:deleted/)?(\\d+)$#',
                $path,
                $match
            )) {
                return null;
            }
        }

        $id = (int)($match[1] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function directory(string $prefix): string
    {
        $this->validatePrefix($prefix);

        return rtrim($this->root, '/\\') .
            '/storage/migration-sql/' .
            trim($prefix, '_');
    }

    private function validatePrefix(string $prefix): void
    {
        if (!preg_match('/^mci_[a-f0-9]{16}_$/', $prefix)) {
            throw new RuntimeException('Invalid SQL migration staging reference.');
        }
    }

    private function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE => 'The Galaxxy data archive exceeds the server upload limit.',
            UPLOAD_ERR_PARTIAL => 'The Galaxxy data archive upload was interrupted. Please upload it again.',
            UPLOAD_ERR_NO_FILE => 'Choose a Galaxxy .zip archive first.',
            default => 'The Galaxxy data archive could not be uploaded.',
        };
    }
}
