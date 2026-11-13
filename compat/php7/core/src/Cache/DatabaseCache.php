<?php

declare (strict_types=1);
namespace MuchoCore\Cache;

use PDO;
use Throwable;
final class DatabaseCache implements CacheInterface
{
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function get(string $key)
    {
        try {
            $key = substr($key, 0, 191);
            $now = time();
            // Filter expired rows directly so every cache lookup is only
            // one indexed SQL statement, including a TTL miss.
            $stmt = $this->pdo->prepare('SELECT cache_value FROM mucho_cache
                 WHERE cache_key=:key
                   AND (expires_at=0 OR expires_at>:now)
                 LIMIT 1');
            $stmt->execute(['key' => $key, 'now' => $now]);
            $value = $stmt->fetchColumn();
            return $value === false ? null : (string) $value;
        } catch (Throwable $__mcCaught) {
            return null;
        }
    }
    public function set(string $key, string $value, int $ttlSeconds)
    {
        try {
            $stmt = $this->pdo->prepare('INSERT INTO mucho_cache(cache_key,cache_value,expires_at)
                 VALUES(:key,:value,:expires)
                 ON DUPLICATE KEY UPDATE
                   cache_value=VALUES(cache_value),
                   expires_at=VALUES(expires_at),
                   updated_at=CURRENT_TIMESTAMP');
            $stmt->execute(['key' => substr($key, 0, 191), 'value' => $value, 'expires' => $ttlSeconds > 0 ? time() + $ttlSeconds : 0]);
            // Bounded opportunistic expiry purge; the worker also cleans.
            if (mt_rand(1, 128) === 1) {
                $this->pruneExpired();
            }
        } catch (Throwable $__mcCaught) {
        }
    }
    public function pruneExpired(int $maxEntries = 128): int
    {
        if ($maxEntries < 1) {
            return 0;
        }
        try {
            $maxEntries = min(1024, $maxEntries);
            $stmt = $this->pdo->prepare('DELETE FROM mucho_cache
                 WHERE expires_at>0 AND expires_at<=:now
                 ORDER BY expires_at ASC
                 LIMIT ' . $maxEntries);
            $stmt->execute(['now' => time()]);
            return $stmt->rowCount();
        } catch (Throwable $__mcCaught) {
            return 0;
        }
    }
    public function delete(string $key)
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM mucho_cache WHERE cache_key=:key');
            $stmt->execute(['key' => substr($key, 0, 191)]);
        } catch (Throwable $__mcCaught) {
        }
    }
    public function deletePrefix(string $prefix)
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM mucho_cache WHERE cache_key LIKE :prefix ESCAPE '!'");
            $stmt->execute(['prefix' => strtr($prefix, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%']);
        } catch (Throwable $__mcCaught) {
        }
    }
    private $pdo;
}
