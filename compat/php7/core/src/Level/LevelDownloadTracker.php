<?php

declare (strict_types=1);
namespace MuchoCore\Level;

use MuchoCore\Core\Environment;
use PDO;
use RuntimeException;
use Throwable;
final class LevelDownloadTracker
{
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function record(int $levelId, string $clientIp): bool
    {
        if (\mucho7_domain_read_only()) {
            return false;
        }
        $clientIp = trim($clientIp);
        if ($levelId <= 0 || filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $clientHash = hash_hmac('sha256', $clientIp, $this->hashKey());
        $window = (int) (Environment::get('MUCHO_DOWNLOAD_DEDUP_SECONDS', '300') ?? '300');
        $window = max(30, min(604800, $window));
        // PHP7 shared workers can race on an empty (level_id,client_hash)
        // range when DELETE runs before INSERT IGNORE. Test for actual
        // expired rows first; absent rows need no gap-locking DELETE.
        // Retry only MariaDB deadlock/lock-timeout failures, never auth,
        // permission, SQL syntax or other unexpected errors.
        for ($attempt = 0; $attempt < 5; $attempt++) {
          try {
            $this->pdo->beginTransaction();
            $expired = $this->pdo->prepare('SELECT slot FROM mucho_level_downloads
                 WHERE level_id=:level_id AND client_hash=:client_hash
                   AND created_at < (CURRENT_TIMESTAMP - INTERVAL ' . $window . ' SECOND)
                 LIMIT 1');
            $expired->execute(['level_id' => $levelId, 'client_hash' => $clientHash]);
            if ($expired->fetchColumn() !== false) {
                $delete = $this->pdo->prepare('DELETE FROM mucho_level_downloads
                     WHERE level_id=:level_id AND client_hash=:client_hash
                       AND created_at < (CURRENT_TIMESTAMP - INTERVAL ' . $window . ' SECOND)');
                $delete->execute(['level_id' => $levelId, 'client_hash' => $clientHash]);
            }
            /*
             * Keep two bounded slots for compatibility with clients that may
             * legitimately issue a paired download request. INSERT IGNORE
             * makes the first-seen path race-safe across PHP workers.
             */
            for ($slot = 1; $slot <= 2; $slot++) {
                $insert = $this->pdo->prepare('INSERT IGNORE INTO mucho_level_downloads
                     (level_id,client_hash,slot)
                     VALUES (:level_id,:client_hash,:slot)');
                $insert->execute(['level_id' => $levelId, 'client_hash' => $clientHash, 'slot' => $slot]);
                if ($insert->rowCount() !== 1) {
                    continue;
                }
                $update = $this->pdo->prepare('UPDATE levels
                     SET downloads=downloads+1
                     WHERE level_id=:level_id
                       AND is_deleted=0');
                $update->execute(['level_id' => $levelId]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Download target disappeared during counter update.');
                }
                $this->pdo->commit();
                return true;
            }
            $this->pdo->commit();
            return false;
          } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                try { $this->pdo->rollBack(); } catch (Throwable $rollback) { /* already rolled back by MariaDB */ }
            }
            $state = $e instanceof \PDOException ? (string) $e->getCode() : '';
            $code = $e instanceof \PDOException && isset($e->errorInfo[1])
                ? (int) $e->errorInfo[1] : 0;
            if ($attempt < 4 && ($state === '40001' || $code === 1213 || $code === 1205)) {
                usleep(2000 * (1 << $attempt) + random_int(0, 3000));
                continue;
            }
            throw new RuntimeException('Download counter transaction failed.', 0, $e);
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
        $window = (int) (Environment::get('MUCHO_DOWNLOAD_DEDUP_SECONDS', '300') ?? '300');
        $window = max(30, min(604800, $window));
        $batch = max(1, min(10000, $batch));
        $stmt = $pdo->prepare('DELETE FROM mucho_level_downloads
             WHERE created_at < (
                 CURRENT_TIMESTAMP - INTERVAL ' . $window . ' SECOND
             )
             ORDER BY created_at ASC
             LIMIT ' . $batch);
        $stmt->execute();
        return $stmt->rowCount();
    }
    private function hashKey(): string
    {
        $configured = trim((string) (Environment::get('MUCHO_DOWNLOAD_HMAC_KEY', '') ?? ''));
        if ($configured !== '') {
            return $configured;
        }
        /*
         * Reuse only a one-way derivation of the protected DB secret so the
         * dedupe hash remains stable across PHP workers without exposing the
         * database password or requiring a new mandatory secret.
         */
        $dbSecret = (string) (Environment::get('DB_PASS', '') ?? '');
        if ($dbSecret !== '') {
            return hash('sha256', 'muchocore-download-dedup|' . $dbSecret);
        }
        static $processFallback = null;
        $processFallback = $processFallback ?? bin2hex(random_bytes(32));
        return $processFallback;
    }
    private $pdo;
}
