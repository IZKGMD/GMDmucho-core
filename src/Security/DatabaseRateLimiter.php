<?php
declare(strict_types=1);

namespace MuchoCore\Security;

use PDO;
use Throwable;

final readonly class DatabaseRateLimiter implements RateLimitBackend
{
    // Match the 24-hour registration device quota in MuchoProtect.
    private const MIN_BUCKET_RETENTION = 86400;

    public function __construct(private PDO $db) {}

    public function allowStrict(string $key, int $limit, int $windowSeconds): bool
    {
        return $limit > 0 && $windowSeconds > 0 && $this->consume($key, $limit, $windowSeconds) === true;
    }

    public function allow(string $key, int $limit, int $windowSeconds): bool
    {
        return $limit < 1 || $windowSeconds < 1 || ($this->consume($key, $limit, $windowSeconds) ?? true);
    }

    /** null means storage was unavailable; false means the quota was exhausted. */
    private function consume(string $key, int $limit, int $windowSeconds): ?bool
    {
        if ($this->db->inTransaction()) {
            // Limiter writes have their own lifetime and must never roll back
            // the caller's transaction on a shared database connection.
            return null;
        }
        $bucket = hash('sha256', $key);
        $now = time();
        $cutoff = max(0, $now - $windowSeconds);

        try {
            $this->db->beginTransaction();
            $increment = $this->db->prepare(
                'INSERT INTO mucho_security_rate_limits (bucket_key,window_start,request_count)
                 VALUES (:bucket,:start,1)
                 ON DUPLICATE KEY UPDATE
                    request_count = IF(window_start <= :reset_count, 1, LEAST(request_count + 1, 4294967295)),
                    window_start = IF(window_start <= :reset_start, VALUES(window_start), window_start),
                    updated_at = CURRENT_TIMESTAMP'
            );
            $increment->execute([
                'bucket' => $bucket,
                'start' => $now,
                'reset_count' => $cutoff,
                'reset_start' => $cutoff,
            ]);

            // The upsert already owns the row lock. Read our own increment
            // before committing so parallel workers see distinct counts.
            $read = $this->db->prepare(
                'SELECT request_count FROM mucho_security_rate_limits WHERE bucket_key=:bucket'
            );
            $read->execute(['bucket' => $bucket]);
            $count = $read->fetchColumn();
            $this->db->commit();

            return $count === false ? null : (float)$count <= $limit;
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return null;
        }
    }

    public function cleanup(int $staleAfterSeconds=self::MIN_BUCKET_RETENTION,int $maxEntries=64): int
    {
        if ($staleAfterSeconds < 1 || $maxEntries < 1) return 0;

        $cutoff = time() - max($staleAfterSeconds, self::MIN_BUCKET_RETENTION);
        $maxEntries = max(1,min(1000,$maxEntries));

        try {
            $stmt = $this->db->prepare(
                "DELETE FROM mucho_security_rate_limits
                 WHERE updated_at < FROM_UNIXTIME(:cutoff)
                 ORDER BY updated_at ASC LIMIT {$maxEntries}"
            );
            $stmt->execute(['cutoff'=>$cutoff]);
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

}
