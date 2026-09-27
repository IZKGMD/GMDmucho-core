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
            getenv('MUCHO_CACHE_DRIVER')
            ?: ($_ENV['MUCHO_CACHE_DRIVER'] ?? 'database')
        )));

        if ($driver === '' || $driver === 'none' || $driver === 'null') {
            return new NullCache();
        }

        if ($driver === 'redis') {
            try {
                return new RedisCache([
                    'host' => getenv('MUCHO_REDIS_HOST') ?: ($_ENV['MUCHO_REDIS_HOST'] ?? '127.0.0.1'),
                    'port' => getenv('MUCHO_REDIS_PORT') ?: ($_ENV['MUCHO_REDIS_PORT'] ?? '6379'),
                    'password' => getenv('MUCHO_REDIS_PASSWORD') ?: ($_ENV['MUCHO_REDIS_PASSWORD'] ?? ''),
                    'database' => getenv('MUCHO_REDIS_DATABASE') ?: ($_ENV['MUCHO_REDIS_DATABASE'] ?? '0'),
                    'timeout' => getenv('MUCHO_REDIS_TIMEOUT') ?: ($_ENV['MUCHO_REDIS_TIMEOUT'] ?? '1.5'),
                ]);
            } catch (Throwable) {
            }
        }

        return new DatabaseCache($pdo);
    }
}
