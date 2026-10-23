<?php

declare (strict_types=1);
namespace MuchoCore\Cache;

interface CacheInterface
{
    public function get(string $key);
    public function set(string $key, string $value, int $ttlSeconds);
    public function delete(string $key);
    public function deletePrefix(string $prefix);
}
