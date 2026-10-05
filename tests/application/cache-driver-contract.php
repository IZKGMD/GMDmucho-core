<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$redis = (string)file_get_contents(
    $root . '/src/Cache/RedisCache.php'
);
$database = (string)file_get_contents(
    $root . '/src/Cache/DatabaseCache.php'
);
$interface = (string)file_get_contents(
    $root . '/src/Cache/CacheInterface.php'
);

if (!str_contains($interface, 'deletePrefix(string $prefix): void')) {
    throw new RuntimeException('Cache interface must expose prefix deletion.');
}

if (!str_contains($database, "LIKE :prefix")) {
    throw new RuntimeException('Database cache prefix deletion contract is missing.');
}

if (!str_contains($database, "LIKE :prefix ESCAPE")) {
    throw new RuntimeException('Database cache prefix deletion must use an explicit LIKE escape character.');
}

if (!str_contains($database, "addcslashes")) {
    throw new RuntimeException('Database cache prefix deletion must escape LIKE wildcards.');
}

if (
    !str_contains($redis, 'if ($keys === false)') ||
    !str_contains($redis, 'return;') ||
    !str_contains($redis, '$iterator !== 0 && $iterator !== false')
) {
    throw new RuntimeException('Redis prefix scan must stop cleanly on scan failure or completion.');
}

if (!str_contains($redis, '$this->redis->del($keys)')) {
    throw new RuntimeException('Redis prefix deletion must delete returned keys.');
}

echo "cache-driver-contract: OK\n";
