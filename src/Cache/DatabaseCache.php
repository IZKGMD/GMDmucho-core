<?php

declare(strict_types=1);

namespace MuchoCore\Cache;

use PDO;
use Throwable;

final readonly class DatabaseCache implements CacheInterface
{
    public function __construct(private PDO $pdo) {}

    public function get(string $key): ?string
    {
        try {
            $key = substr($key, 0, 191);
            $now = time();
            // Only one indexed SELECT per lookup, including expired keys.
            // The old path selected expired payloads and then executed a
            // second DELETE (and extra round trip) on every TTL miss.
            $stmt = $this->pdo->prepare(
                'SELECT cache_value FROM mucho_cache
                 WHERE cache_key=:key
                   AND (expires_at=0 OR expires_at>:now)
                 LIMIT 1'
            );
            $stmt->execute(['key' => $key, 'now' => $now]);
            $value = $stmt->fetchColumn();
            return $value === false ? null : (string)$value;
        } catch (Throwable) {
            return null;
        }
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO mucho_cache(cache_key,cache_value,expires_at)
                 VALUES(:key,:value,:expires)
                 ON DUPLICATE KEY UPDATE
                   cache_value=VALUES(cache_value),
                   expires_at=VALUES(expires_at),
                   updated_at=CURRENT_TIMESTAMP'
            );
            $stmt->execute([
                'key' => substr($key, 0, 191),
                'value' => $value,
                'expires' => $ttlSeconds > 0 ? time() + $ttlSeconds : 0,
            ]);

            // The worker also handles expiration. As a backstop when it is
            // unavailable, amortize a bounded purge over successful writes.
            // Do not perform DELETEs on read requests or on every cache set.
            if (mt_rand(1, 128) === 1) {
                $this->pruneExpired();
            }
        } catch (Throwable) {
        }
    }

    /**
     * Reclaim only expired cache entries, using idx_mcache_expiry.
     * A concurrent refresh cannot be deleted because the expiry predicate
     * is evaluated atomically by the database at DELETE time.
     */
    public function pruneExpired(int $maxEntries = 128): int
    {
        if ($maxEntries < 1) {
            return 0;
        }

        try {
            $maxEntries = min(1024, $maxEntries);
            $stmt = $this->pdo->prepare(
                'DELETE FROM mucho_cache
                 WHERE expires_at>0 AND expires_at<=:now
                 ORDER BY expires_at ASC
                 LIMIT ' . $maxEntries
            );
            $stmt->execute(['now' => time()]);
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    public function delete(string $key): void
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM mucho_cache WHERE cache_key=:key');
            $stmt->execute(['key' => substr($key, 0, 191)]);
        } catch (Throwable) {
        }
    }

    public function deletePrefix(string $prefix): void
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM mucho_cache WHERE cache_key LIKE :prefix ESCAPE '!'");
            $stmt->execute(['prefix' => strtr($prefix, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%']);
        } catch (Throwable) {
        }
    }
}
