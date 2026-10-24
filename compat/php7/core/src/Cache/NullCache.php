<?php

declare (strict_types=1);
namespace MuchoCore\Cache;

final class NullCache implements CacheInterface
{
    public function get(string $key)
    {
        return null;
    }
    public function set(string $key, string $value, int $ttlSeconds)
    {
    }
    public function delete(string $key)
    {
    }
    public function deletePrefix(string $prefix)
    {
    }
}
