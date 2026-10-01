<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use MuchoCore\Backup\DatabaseBackupService;
use MuchoCore\Database\Migrator;
use PDO;
use RuntimeException;
use Throwable;

final class SqlDumpMigrationService
{
    private readonly MigrationDatabaseAdapterRegistry $databaseAdapters;
    private readonly MigrationServerArchiveAdapterRegistry $serverArchives;

    private const MAX_UPLOAD_BYTES = 268435456;
    private const MAX_UNCOMPRESSED_BYTES = 536870912;
    private const STALE_AFTER_SECONDS = 86400;

    /**
     * @var list<string>
     */
    private const SOURCE_TABLES = [
        'accounts',
        'users',
        'levels',
        'levelscores',
        'platscores',
        'comments',
        'acccomments',
        'friendships',
        'friendreqs',
        'blocks',
        'messages',
        'links',
        'lists',
        'mappacks',
        'gauntlets',
        'dailyfeatures',
        'roles',
        'roleassign',
        'modips',
        'bannedips',
        'reports',
        'modactions',
        'actions',
        'suggest',
        'modipperms',
        'songs',
        'actions_downloads',
        'actions_likes',
        'cpshares',
    ];

    public function __construct(
        private readonly PDO $target,
        private readonly string $root,
        private readonly string $backupDirectory,
    ) {
        $this->databaseAdapters = new MigrationDatabaseAdapterRegistry();
        $this->serverArchives = new MigrationServerArchiveAdapterRegistry($this->root);

        if (!is_dir($this->storageDirectory())
            && !mkdir($this->storageDirectory(), 0700, true)
            && !is_dir($this->storageDirectory())) {
            throw new RuntimeException('Unable to create SQL migration storage.');
        }

        $this->cleanupStale();
    }

    /**
     * @param array<string,mixed> $upload
     * @param array<string,mixed>|null $archiveUpload
     * @return array{
     *   prefix:string,
     *   filename:string,
     *   inspection:array<string,mixed>,
     *   preflight:array<string,int>,
     *   server_archive:array{
     *     adapter:string,
     *     level_data:array{stored_files:int,matched_files:int,stored_bytes:int,matched_bytes:int},
     *     cloud_saves:array{stored_files:int,matched_files:int,stored_bytes:int,matched_bytes:int}
     *   }
     * }
     */
    public function stageUpload(array $upload, ?array $archiveUpload = null): array
    {
        $this->cleanupStale();

        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadError($error));
        }

        $tmp = (string)($upload['tmp_name'] ?? '');
        $name = trim((string)($upload['name'] ?? ''));
        $size = (int)($upload['size'] ?? 0);

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('The uploaded SQL file is not available.');
        }

        if ($size < 1 || $size > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException(
                'SQL file must be between 1 byte and 256 MB.'
            );
        }

        $lower = strtolower($name);
        if (!str_ends_with($lower, '.sql') && !str_ends_with($lower, '.sql.gz')) {
            throw new RuntimeException(
                'Only .sql and .sql.gz database dumps are supported.'
            );
        }

        $prefix = 'mci_' . bin2hex(random_bytes(8)) . '_';

        try {
            $this->importIntoStaging($tmp, $lower, $prefix);

            $source = new SourceDetector($prefix);
            $inspection = $source->inspect($this->target);

            if (($inspection['engine'] ?? 'unknown') !== 'cvolton') {
                throw new RuntimeException(
                    'Unsupported SQL dump schema. The file must contain a Cvolton/MegaSa1nt-compatible accounts, users and levels schema.'
                );
            }

            $databaseAdapter = $this->databaseAdapters->resolve(
                $this->target,
                $prefix,
                $inspection
            );
            $preflight = $databaseAdapter->preflight($this->target);

            $serverArchive = [
                'adapter' => '',
                'level_data' => [
                    'stored_files' => 0,
                    'matched_files' => 0,
                    'stored_bytes' => 0,
                    'matched_bytes' => 0,
                ],
                'cloud_saves' => [
                    'stored_files' => 0,
                    'matched_files' => 0,
                    'stored_bytes' => 0,
                    'matched_bytes' => 0,
                ],
            ];

            if ($archiveUpload !== null) {
                $levelIds = $this->sourceLevelIds($prefix);
                $accountIds = $this->sourceAccountIds($prefix);
                $archiveAdapter = $this->serverArchives->resolveUpload($archiveUpload);
                $serverArchive = [
                    'adapter' => $archiveAdapter->key(),
                    ...$archiveAdapter->stageUpload(
                        $archiveUpload,
                        $levelIds,
                        $accountIds,
                        $prefix
                    ),
                ];
            }

            $this->writeJobMarker(
                $prefix,
                [
                    'prefix' => $prefix,
                    'filename' => $this->safeFilename($name),
                    'created_at' => time(),
                ]
            );

            return [
                'prefix' => $prefix,
                'filename' => $this->safeFilename($name),
                'inspection' => $inspection,
                'preflight' => $preflight,
                'server_archive' => $serverArchive,
            ];
        } catch (Throwable $e) {
            $this->dropStaging($prefix);
            throw $e;
        }
    }

    /**
     * @return array{
     *   inspection:array<string,mixed>,
     *   preflight:array<string,int>
     * }
     */
    public function previewStaged(string $prefix): array
    {
        $this->validatePrefix($prefix);

        $inspection = (new SourceDetector($prefix))->inspect($this->target);

        if (($inspection['engine'] ?? 'unknown') !== 'cvolton') {
            throw new RuntimeException(
                'Staged SQL dump is no longer a supported Cvolton-compatible schema.'
            );
        }

        $databaseAdapter = $this->databaseAdapters->resolve(
            $this->target,
            $prefix,
            $inspection
        );
        $preflight = $databaseAdapter->preflight($this->target);

        return [
            'inspection' => $inspection,
            'preflight' => $preflight,
        ];
    }

    /**
     * @return array{
     *   inspection:array<string,mixed>,
     *   preflight:array<string,int>,
     *   backup:array{file:string,sha256:string,size:int},
     *   stats:array<string,int>
     * }
     */
    public function applyStaged(string $prefix): array
    {
        $this->validatePrefix($prefix);

        $preview = $this->previewStaged($prefix);
        $databaseAdapter = $this->databaseAdapters->resolve(
            $this->target,
            $prefix,
            $preview['inspection']
        );

        $backup = (new DatabaseBackupService(
            $this->target,
            $this->backupDirectory,
            ['mci_']
        ))->create('muchocore-before-sql-import');

        /*
         * Migrator emits human-readable status lines to stdout. The SQL file
         * import runs inside an HTTP request, so keep those lines out of the
         * response buffer or they can break the redirect headers.
         */
        ob_start();

        try {
            (new Migrator(
                $this->target,
                rtrim($this->root, '/\\') . '/database/migrations'
            ))->migrate();
        } finally {
            ob_end_clean();
        }

        $this->target->beginTransaction();

        try {
            $stats = $databaseAdapter->apply($this->target);
            $archiveStats = $this->hydrateServerArchive($prefix);
            foreach ($archiveStats as $name => $value) {
                $stats[$name] = $value;
            }
        } catch (Throwable $e) {
            if ($this->target->inTransaction()) {
                $this->target->rollBack();
            }
            throw $e;
        }

        try {
            $this->dropStaging($prefix);
        } catch (Throwable) {
            // The production transaction is already committed. Cleanup failure
            // must not make a successful migration look like a failed import.
        }

        return [
            'inspection' => $preview['inspection'],
            'preflight' => $preview['preflight'],
            'backup' => $backup,
            'stats' => $stats,
        ];
    }

    public function discard(string $prefix): void
    {
        $this->validatePrefix($prefix);
        $this->dropStaging($prefix);
    }

    public function cleanupStale(): void
    {
        foreach (glob($this->storageDirectory() . '/*.json') ?: [] as $marker) {
            if (!is_file($marker)) {
                continue;
            }

            $data = json_decode(
                (string)file_get_contents($marker),
                true
            );

            if (!is_array($data)) {
                @unlink($marker);
                continue;
            }

            $created = (int)($data['created_at'] ?? 0);
            $prefix = (string)($data['prefix'] ?? '');

            if ($created > 0 && time() - $created < self::STALE_AFTER_SECONDS) {
                continue;
            }

            try {
                $this->validatePrefix($prefix);
                $this->dropStaging($prefix);
            } catch (Throwable) {
                // A stale cleanup pass must never break a new upload.
            }

            @unlink($marker);
        }
    }

    private function importIntoStaging(
        string $file,
        string $lowerName,
        string $prefix
    ): void {
        $handle = false;

        if (str_ends_with($lowerName, '.sql.gz')) {
            $handle = gzopen($file, 'rb');
        } else {
            $handle = fopen($file, 'rb');
        }

        if ($handle === false) {
            throw new RuntimeException('Unable to open the SQL dump.');
        }

        try {
            $this->target->exec('SET FOREIGN_KEY_CHECKS=0');

            $statements = SqlDumpTokenizer::statements(
                $handle,
                self::MAX_UNCOMPRESSED_BYTES
            );

            foreach ($statements as $statement) {
                $this->executeDumpStatement($statement, $prefix);
            }
        } finally {
            if (str_ends_with($lowerName, '.sql.gz')) {
                gzclose($handle);
            } else {
                fclose($handle);
            }

            try {
                $this->target->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable) {
            }
        }
    }

    private function executeDumpStatement(
        string $statement,
        string $prefix
    ): void {
        $trimmed = trim($statement);

        if ($trimmed === '') {
            return;
        }

        $trimmed = preg_replace('/^\xEF\xBB\xBF/', '', $trimmed) ?? $trimmed;

        if (preg_match('/^DELIMITER\b/i', $trimmed)) {
            throw new RuntimeException(
                'This SQL dump contains DELIMITER directives. Export the database without stored procedures/triggers, then upload the table dump.'
            );
        }

        if (preg_match(
            '/^(?:SET|START\s+TRANSACTION|COMMIT|ROLLBACK)\b/i',
            $trimmed
        )) {
            return;
        }

        if (preg_match(
            '/^(?:USE|CREATE\s+DATABASE|DROP\s+DATABASE|ALTER\s+DATABASE|GRANT|REVOKE|INSTALL|UNINSTALL|LOAD\s+DATA|CALL)\b/i',
            $trimmed
        )) {
            return;
        }

        $table = null;

        if (preg_match(
            '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:(?:\x60[^\x60]+\x60|[A-Za-z0-9_$.-]+)\.)?(?:\x60([^\x60]+)\x60|([A-Za-z0-9_$.-]+))/i',
            $trimmed,
            $match,
            PREG_OFFSET_CAPTURE
        )) {
            $table = $this->matchedTable($match);
        } elseif (preg_match(
            '/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?(?:(?:\x60[^\x60]+\x60|[A-Za-z0-9_$.-]+)\.)?(?:\x60([^\x60]+)\x60|([A-Za-z0-9_$.-]+))/i',
            $trimmed,
            $match,
            PREG_OFFSET_CAPTURE
        )) {
            $table = $this->matchedTable($match);
        } elseif (preg_match(
            '/^(?:INSERT(?:\s+(?:IGNORE|LOW_PRIORITY|DELAYED))*\s+INTO|REPLACE\s+INTO)\s+(?:(?:\x60[^\x60]+\x60|[A-Za-z0-9_$.-]+)\.)?(?:\x60([^\x60]+)\x60|([A-Za-z0-9_$.-]+))/i',
            $trimmed,
            $match,
            PREG_OFFSET_CAPTURE
        )) {
            $table = $this->matchedTable($match);
        } else {
            return;
        }

        if (!in_array($table, self::SOURCE_TABLES, true)) {
            return;
        }

        $sql = $this->replaceFirstTableReference(
            $trimmed,
            $prefix . $table
        );

        $this->target->exec($sql);
    }

    /**
     * @param array<int,array{0:string,1:int}> $match
     */
    private function matchedTable(array $match): ?string
    {
        $value = (string)($match[1][0] ?? $match[2][0] ?? '');

        if ($value === '' || !preg_match('/^[A-Za-z0-9_$.-]{1,64}$/', $value)) {
            return null;
        }

        return $value;
    }

    private function replaceFirstTableReference(
        string $sql,
        string $table
    ): string {
        $offset = 0;

        if (preg_match(
            '/^(CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?|DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?|(?:INSERT(?:\s+(?:IGNORE|LOW_PRIORITY|DELAYED))*\s+INTO|REPLACE\s+INTO)\s+)((?:(?:\x60[^\x60]+\x60|[A-Za-z0-9_$.-]+)\.)?(?:\x60[^\x60]+\x60|[A-Za-z0-9_$.-]+))/i',
            $sql,
            $match,
            PREG_OFFSET_CAPTURE
        )) {
            $offset = (int)$match[2][1];
            $length = strlen((string)$match[2][0]);

            return substr_replace(
                $sql,
                chr(96) . str_replace(chr(96), chr(96) . chr(96), $table) . chr(96),
                $offset,
                $length
            );
        }

        throw new RuntimeException('Unable to safely isolate the SQL table name.');
    }

    /**
     * @return array<string,int>
     */
    private function hydrateServerArchive(string $prefix): array
    {
        $levelIds = $this->sourceLevelIds($prefix);
        $accountIds = $this->sourceAccountIds($prefix);

        $totals = [
            'level_data_hydrated' => 0,
            'level_data_missing' => 0,
            'level_data_bytes' => 0,
            'cloud_saves_imported' => 0,
            'cloud_saves_missing' => 0,
            'cloud_saves_bytes' => 0,
        ];

        foreach ($this->serverArchives->instances() as $adapter) {
            $result = $adapter->hydrate(
                $this->target,
                $prefix,
                $levelIds,
                $accountIds
            );

            foreach ($totals as $name => $unused) {
                $totals[$name] += (int)($result[$name] ?? 0);
            }

            if (
                $totals['level_data_hydrated'] > 0 ||
                $totals['cloud_saves_imported'] > 0
            ) {
                break;
            }
        }

        return $totals;
    }

    /**
     * @return list<int>
     */
    private function sourceLevelIds(string $prefix): array
    {
        return array_map(
            'intval',
            $this->target->query(
                'SELECT levelID FROM ' . $this->quoteTable($prefix . 'levels') .
                ' ORDER BY levelID ASC'
            )->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /**
     * @return list<int>
     */
    private function sourceAccountIds(string $prefix): array
    {
        return array_map(
            'intval',
            $this->target->query(
                'SELECT accountID FROM ' . $this->quoteTable($prefix . 'accounts') .
                ' ORDER BY accountID ASC'
            )->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    private function quoteTable(string $table): string
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $table)) {
            throw new RuntimeException('Invalid SQL migration staging table.');
        }

        return chr(96) . str_replace(chr(96), chr(96) . chr(96), $table) . chr(96);
    }

    private function writeJobMarker(string $prefix, array $data): void
    {
        $path = $this->markerPath($prefix);

        if (file_put_contents(
            $path,
            json_encode(
                $data,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) . PHP_EOL,
            LOCK_EX
        ) === false) {
            throw new RuntimeException('Unable to persist SQL migration state.');
        }

        @chmod($path, 0600);
    }

    private function markerPath(string $prefix): string
    {
        return $this->storageDirectory() . '/' . trim($prefix, '_') . '.json';
    }

    private function storageDirectory(): string
    {
        return rtrim($this->root, '/\\') . '/storage/migration-sql';
    }

    private function dropStaging(string $prefix): void
    {
        if (!preg_match('/^mci_[a-f0-9]{16}_$/', $prefix)) {
            return;
        }

        foreach (self::SOURCE_TABLES as $table) {
            $this->target->exec(
                'DROP TABLE IF EXISTS ' .
                chr(96) .
                $prefix . $table .
                chr(96)
            );
        }

        foreach ($this->serverArchives->instances() as $adapter) {
            try {
                $adapter->cleanup($prefix);
            } catch (Throwable) {
                // Cleanup must not prevent other staging data from being removed.
            }
        }

        @unlink($this->markerPath($prefix));
    }

    private function validatePrefix(string $prefix): void
    {
        if (!preg_match('/^mci_[a-f0-9]{16}_$/', $prefix)) {
            throw new RuntimeException('Invalid SQL migration staging reference.');
        }
    }

    private function safeFilename(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[^A-Za-z0-9_.-]/', '_', $base) ?? 'database.sql';

        return substr($base, 0, 180) !== ''
            ? substr($base, 0, 180)
            : 'database.sql';
    }

    private function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE => 'The uploaded SQL file exceeds the server upload limit.',
            UPLOAD_ERR_PARTIAL => 'The SQL upload was interrupted. Please upload the file again.',
            UPLOAD_ERR_NO_FILE => 'Choose a database.sql file first.',
            default => 'The SQL upload could not be completed.',
        };
    }
}
