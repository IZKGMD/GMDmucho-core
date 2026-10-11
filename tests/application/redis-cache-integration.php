<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Cache/CacheInterface.php';
require dirname(__DIR__, 2) . '/src/Cache/RedisCache.php';

use MuchoCore\Cache\RedisCache;

if (getenv('TEST_REDIS_HOST') !== '127.0.0.1' || !class_exists(Redis::class)) {
    throw new RuntimeException('Requires the isolated CI Redis and PHP Redis extension.');
}
$port = (int)(getenv('TEST_REDIS_PORT') ?: 6379);
$redis = new Redis();
$redis->connect('127.0.0.1', $port, 2);
$cache = new RedisCache(['host' => '127.0.0.1', 'port' => $port]);
$namespace = 'mc-cache-test-' . bin2hex(random_bytes(6)) . ':';
$keys = [];
try {
    // More than one SCAN batch, and glob operators must remain literal.
    $prefix = $namespace . 'unit*?[abc]\\:';
    for ($i = 0; $i < 450; ++$i) {
        $key = $prefix . $i;
        $cache->set($key, 'match', 60);
        $keys[] = $key;
    }
    $untouched = [$namespace . 'unitXa:', $namespace . 'unitanything', $namespace . 'other'];
    foreach ($untouched as $key) {
        $cache->set($key, 'keep', 60);
        $keys[] = $key;
    }
    $cache->deletePrefix($prefix);
    foreach (array_slice($keys, 0, 450) as $key) {
        if ($cache->get($key) !== null) { throw new RuntimeException('SCAN missed an entry: ' . $key); }
    }
    foreach ($untouched as $key) {
        if ($cache->get($key) !== 'keep') { throw new RuntimeException('Prefix glob deleted an unrelated key.'); }
    }
    echo "REDIS_LITERAL_PREFIX_SCAN_OK\n";
} finally {
    $redis->del($keys);
}
