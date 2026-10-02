<?php

declare(strict_types=1);

namespace MuchoCore\Cache;

use PDO;
use Throwable;

final class CacheManager
{
    public static function fromEnvironment(PDO $pdo): CacheInterface
    {
        $driver = strtolower(trim((string)(
            $_ENV['MUCHO_CACHE_DRIVER']
            ?? getenv('MUCHO_CACHE_DRIVER')
            ?? 'database'
        )));

        if ($driver === '' || $driver === 'none' || $driver === 'null') {
            return new NullCache();
        }

        if ($driver === 'redis') {
            try {
                return new RedisCache([
                    'host' => $_ENV['MUCHO_REDIS_HOST'] ?? getenv('MUCHO_REDIS_HOST') ?: '127.0.0.1',
                    'port' => $_ENV['MUCHO_REDIS_PORT'] ?? getenv('MUCHO_REDIS_PORT') ?: '6379',
                    'password' => $_ENV['MUCHO_REDIS_PASSWORD'] ?? getenv('MUCHO_REDIS_PASSWORD') ?: '',
                    'database' => $_ENV['MUCHO_REDIS_DATABASE'] ?? getenv('MUCHO_REDIS_DATABASE') ?: '0',
                    'timeout' => $_ENV['MUCHO_REDIS_TIMEOUT'] ?? getenv('MUCHO_REDIS_TIMEOUT') ?: '1.5',
                ]);
            } catch (Throwable) {
            }
        }

        return new DatabaseCache($pdo);
    }
}
