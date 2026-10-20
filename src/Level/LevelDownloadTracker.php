<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Core\Environment;
use PDO;
use RuntimeException;
use Throwable;

final readonly class LevelDownloadTracker
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function record(
        int $levelId,
        string $clientIp
    ): bool {
        $clientIp = trim($clientIp);

        if (
            $levelId <= 0 ||
            filter_var($clientIp, FILTER_VALIDATE_IP) === false
        ) {
            return false;
        }

        $clientHash = hash_hmac(
            'sha256',
            $clientIp,
            $this->hashKey()
        );

        $window = (int)(Environment::get(
            'MUCHO_DOWNLOAD_DEDUP_SECONDS',
            '300'
        ) ?? '300');
        $window = max(30, min(604800, $window));

        $this->pdo->beginTransaction();

        try {
            // The unique index starts with (level_id, client_hash). Avoid
            // deleting every expired record for a popular level on each
            // download: clean only the requesting client's expired slots.
            $delete = $this->pdo->prepare(
                'DELETE FROM mucho_level_downloads
                 WHERE level_id=:level_id
                   AND client_hash=:client_hash
                   AND created_at < (
                       CURRENT_TIMESTAMP - INTERVAL '.$window.' SECOND
                   )'
            );
            $delete->execute([
                'level_id' => $levelId,
                'client_hash' => $clientHash,
            ]);

            /*
             * Keep two bounded slots for compatibility with clients that may
             * legitimately issue a paired download request. INSERT IGNORE
             * makes the first-seen path race-safe across PHP workers.
             */
            for ($slot = 1; $slot <= 2; $slot++) {
                $insert = $this->pdo->prepare(
                    'INSERT IGNORE INTO mucho_level_downloads
                     (level_id,client_hash,slot)
                     VALUES (:level_id,:client_hash,:slot)'
                );

                $insert->execute([
                    'level_id' => $levelId,
                    'client_hash' => $clientHash,
                    'slot' => $slot,
                ]);

                if ($insert->rowCount() !== 1) {
                    continue;
                }

                $update = $this->pdo->prepare(
                    'UPDATE levels
                     SET downloads=downloads+1
                     WHERE level_id=:level_id
                       AND is_deleted=0'
                );
                $update->execute([
                    'level_id' => $levelId,
                ]);

                if ($update->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Download target disappeared during counter update.'
                    );
                }

                $this->pdo->commit();
                return true;
            }

            $this->pdo->commit();
            return false;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException(
                'Download counter transaction failed.',
                0,
                $e
            );
        }
    }

    /**
     * Bounded cleanup for the background worker, never the request path.
     * The window matches record() so active deduplication slots survive.
     */
    public static function pruneExpired(PDO $pdo, int $batch = 512): int
    {
        $window = (int)(Environment::get(
            'MUCHO_DOWNLOAD_DEDUP_SECONDS', '300'
        ) ?? '300');
        $window = max(30, min(604800, $window));
        $batch = max(1, min(10000, $batch));
        $stmt = $pdo->prepare(
            'DELETE FROM mucho_level_downloads
             WHERE created_at < (
                 CURRENT_TIMESTAMP - INTERVAL ' . $window . ' SECOND
             )
             ORDER BY created_at ASC
             LIMIT ' . $batch
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    private function hashKey(): string
    {
        $configured = trim((string)(
            Environment::get('MUCHO_DOWNLOAD_HMAC_KEY', '')
            ?? ''
        ));

        if ($configured !== '') {
            return $configured;
        }

        /*
         * Reuse only a one-way derivation of the protected DB secret so the
         * dedupe hash remains stable across PHP workers without exposing the
         * database password or requiring a new mandatory secret.
         */
        $dbSecret = (string)(Environment::get('DB_PASS', '') ?? '');

        if ($dbSecret !== '') {
            return hash(
                'sha256',
                'muchocore-download-dedup|' . $dbSecret
            );
        }

        static $processFallback = null;
        $processFallback ??= bin2hex(random_bytes(32));

        return $processFallback;
    }
}
