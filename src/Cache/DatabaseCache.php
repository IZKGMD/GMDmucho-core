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
            $stmt = $this->pdo->prepare(
                'SELECT cache_value FROM mucho_cache
                 WHERE cache_key = :key
                   AND (expires_at = 0 OR expires_at > :now)
                 LIMIT 1'
            );
            $stmt->execute(['key' => substr($key, 0, 191), 'now' => time()]);
            $value = $stmt->fetchColumn();
            if ($value === false) {
                $this->delete($key);
                return null;
            }
            return (string)$value;
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
        } catch (Throwable) {
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
            $literalPrefix = substr($prefix, 0, 190);
            $stmt = $this->pdo->prepare(
                "DELETE FROM mucho_cache
                 WHERE cache_key LIKE :prefix ESCAPE '\\\\'"
            );
            $stmt->execute([
                'prefix' =>
                    addcslashes($literalPrefix, '\\\\%_') . '%',
            ]);
        } catch (Throwable) {
        }
    }
}
