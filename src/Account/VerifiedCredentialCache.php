<?php

declare(strict_types=1);

namespace MuchoCore\Account;

use MuchoCore\Core\Environment;

final class VerifiedCredentialCache
{
    private const TTL = 30;

    public static function verify(string $credential, string $storedHash): bool
    {
        $secret = self::secret();
        if ($secret === null || $storedHash === '') {
            return password_verify($credential, $storedHash);
        }

        // Password changes rotate the cache key automatically. Cache entries
        // contain only a keyed digest and live in this PHP pool's memory.
        $key = self::prefix() . hash_hmac('sha256', $storedHash, $secret);
        $proof = hash_hmac('sha256', $key . "\0" . $credential, $secret, true);
        $cached = apcu_fetch($key);
        if (is_string($cached) && hash_equals($cached, $proof)) {
            return true;
        }

        if (!password_verify($credential, $storedHash)) {
            return false;
        }

        apcu_store($key, $proof, self::TTL);
        return true;
    }

    private static function secret(): ?string
    {
        if (
            Environment::get('MUCHO_AUTH_CACHE', '0') !== '1'
            || !function_exists('apcu_enabled')
            || !apcu_enabled()
        ) {
            return null;
        }

        $key = self::prefix() . 'key';
        $secret = apcu_fetch($key);
        if (!is_string($secret)) {
            apcu_add($key, random_bytes(32));
            $secret = apcu_fetch($key);
        }

        return is_string($secret) && strlen($secret) === 32 ? $secret : null;
    }

    private static function prefix(): string
    {
        return 'muchocore:credential:v1:' . dirname(__DIR__, 2) . ':';
    }
}
