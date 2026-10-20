<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Cache\FileCache;

function fileCacheCheck(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
}

$dir = sys_get_temp_dir() . '/mucho-file-cache-' . bin2hex(random_bytes(8));
$cache = new FileCache($dir);
$otherWorker = new FileCache($dir);

try {
    fileCacheCheck($cache->get('mucho:levels:absent') === null,
        'an unpopulated cache is a miss');

    $key = 'mucho:levels:page0';
    $data = "1:2:4:a#10:3|binary\x00\xff";
    $cache->set($key, $data, 120);
    fileCacheCheck($cache->get($key) === $data,
        'GD response bytes are preserved, including arbitrary byte sequences');
    fileCacheCheck($otherWorker->get($key) === $data,
        'separate cache instances share the same saved response');

    $hash = hash('sha256', $key);
    $file = $dir . '/' . substr($hash, 0, 2) . '/' . $hash . '.json';
    fileCacheCheck(is_file($file) && (fileperms($file) & 0077) === 0,
        'cached response stored outside public in a private file');

    $cache->set('mucho:levels:p1', 'a', 30);
    $cache->set('mucho:levels:p2', 'b', 30);
    $cache->set('other:data', 'keep', 30);
    $otherWorker->deletePrefix('mucho:levels:');
    fileCacheCheck($cache->get($key) === null
        && $cache->get('mucho:levels:p1') === null
        && $cache->get('mucho:levels:p2') === null
        && $cache->get('other:data') === 'keep',
        'cross-worker invalidation removes exactly the level-list prefix');

    // Prefix matching is literal, not a SQL-LIKE or glob expression.
    $cache->set('unit%_!:one', 'a', 30);
    $cache->set('unit%_!:two', 'b', 30);
    $cache->set('unitAA!:other', 'keep', 30);
    $cache->deletePrefix('unit%_!:');
    fileCacheCheck($cache->get('unit%_!:one') === null
        && $cache->get('unit%_!:two') === null
        && $cache->get('unitAA!:other') === 'keep',
        'prefix containing SQL wildcards is matched literally');

    $expiredKey = 'mucho:levels:expired';
    $cache->set($expiredKey, 'expired-value', 30);
    $expiredHash = hash('sha256', $expiredKey);
    $expiredPath = $dir . '/' . substr($expiredHash, 0, 2)
        . '/' . $expiredHash . '.json';
    $entry = json_decode((string)file_get_contents($expiredPath), true,
        512, JSON_THROW_ON_ERROR);
    $entry['expires'] = time() - 1;
    file_put_contents($expiredPath, json_encode($entry, JSON_THROW_ON_ERROR));
    fileCacheCheck($otherWorker->get($expiredKey) === null,
        'expired entry is not served, even before background cleanup');
    $otherWorker->set($expiredKey, 'new-value', 30);
    fileCacheCheck($cache->get($expiredKey) === 'new-value',
        'expired entry can be refreshed without losing the new value');

    $cache->set('never-expire', 'always', 0);
    fileCacheCheck($cache->get('never-expire') === 'always',
        'TTL zero retains nonexpiring DatabaseCache semantics');
    $cache->delete('never-expire');
    fileCacheCheck($cache->get('never-expire') === null,
        'delete removes a single entry');

    $cache->set(str_repeat('z', 513), 'should-not-exist', 30);
    fileCacheCheck($cache->get(str_repeat('z', 513)) === null,
        'unreasonably long keys are rejected');

    $cache->set('bad-json', 'x', 60);
    $badHash = hash('sha256', 'bad-json');
    file_put_contents($dir . '/' . substr($badHash, 0, 2)
        . '/' . $badHash . '.json', '{broken');
    fileCacheCheck($cache->get('bad-json') === null,
        'corrupt files fail closed as cache misses');
} finally {
    if (is_dir($dir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}

echo "MUCHOCORE_FILE_CACHE_OK\n";
