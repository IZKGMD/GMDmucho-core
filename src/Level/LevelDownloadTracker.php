<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Core\Environment;
use PDO;
use PDOException;
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

        // A saturated window cannot add a download. Check its two indexed
        // slots without opening a write transaction or locking a popular level.
        $slots = $this->pdo->prepare(
            'SELECT COUNT(*) FROM mucho_level_downloads
             WHERE level_id=:level_id
               AND client_hash=:client_hash
               AND created_at >= (CURRENT_TIMESTAMP - INTERVAL ' . $window . ' SECOND)'
        );
        $slots->execute(['level_id' => $levelId, 'client_hash' => $clientHash]);
        if ((int)$slots->fetchColumn() >= 2) {
            return false;
        }

        for ($attempt = 0; ; $attempt++) {
            $this->pdo->beginTransaction();
            try {
                $counted = $this->claimDownload($levelId, $clientHash, $window);
                $this->pdo->commit();
                return $counted;
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $driverCode = $error instanceof PDOException ? (int)($error->errorInfo[1] ?? 0) : 0;
                if ($attempt < 3 && ($error->getCode() === '40001'
                    || $driverCode === 1213 || $driverCode === 1205)) {
                    usleep(2000 * (1 << $attempt) + random_int(0, 3000));
                    continue;
                }
                throw new RuntimeException('Download counter transaction failed.', 0, $error);
            }
        }
    }

    private function claimDownload(int $levelId, string $clientHash, int $window): bool
    {
        // Take the same level lock before touching any slots. All counters for
        // this level update that row anyway. Establish a consistent lock order.
        $level = $this->pdo->prepare('SELECT level_id FROM levels WHERE level_id=? AND is_deleted=0 FOR UPDATE');
        $level->execute([$levelId]);
        if ($level->fetchColumn() === false) {
            return false;
        }
        $params = ['level_id' => $levelId, 'client_hash' => $clientHash];
        $clientSlots = $this->pdo->prepare(
            'SELECT slot, created_at >= (CURRENT_TIMESTAMP - INTERVAL '.$window.' SECOND) AS active
             FROM mucho_level_downloads WHERE level_id=:level_id AND client_hash=:client_hash'
        );
        $clientSlots->execute($params);
        $knownSlots = $clientSlots->fetchAll(PDO::FETCH_KEY_PAIR);
        $activeSlots = array_filter($knownSlots);
        if (count($activeSlots) >= 2) {
            return false;
        }
        if (count($activeSlots) !== count($knownSlots)) {
            $delete = $this->pdo->prepare(
                'DELETE FROM mucho_level_downloads
                 WHERE level_id=:level_id
                   AND client_hash=:client_hash
                   AND created_at < (
                       CURRENT_TIMESTAMP - INTERVAL '.$window.' SECOND
                   )'
            );
            $delete->execute($params);
        }
        // Keep the unique-key backstop when other runtimes use the same table.
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO mucho_level_downloads
             (level_id,client_hash,slot) VALUES (:level_id,:client_hash,:slot)'
        );
        for ($slot = 1; $slot <= 2; $slot++) {
            if (isset($activeSlots[$slot])) {
                continue;
            }
            $insert->execute($params + ['slot' => $slot]);
            if ($insert->rowCount() === 1) {
                $update = $this->pdo->prepare('UPDATE levels SET downloads=downloads+1 WHERE level_id=?');
                $update->execute([$levelId]);
                return true;
            }
        }
        return false;
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
