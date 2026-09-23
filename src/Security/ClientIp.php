<?php

declare(strict_types=1);

namespace MuchoCore\Security;

/**
 * Resolve a client address without trusting spoofable forwarding headers.
 *
 * Forwarded headers are considered only when REMOTE_ADDR belongs to an
 * explicitly configured trusted proxy. This is important on shared hosting,
 * where a client can otherwise forge X-Forwarded-For / CF-Connecting-IP.
 */
final class ClientIp
{
    /** @param array<string,mixed> $server */
    public static function resolve(array $server): string
    {
        $remote = self::validIp($server['REMOTE_ADDR'] ?? '');
        if ($remote === null) {
            return '0.0.0.0';
        }

        if (!self::isTrustedProxy($remote)) {
            return $remote;
        }

        $cloudflare = self::validIp($server['HTTP_CF_CONNECTING_IP'] ?? '');
        if ($cloudflare !== null) {
            return $cloudflare;
        }

        $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($forwarded) && $forwarded !== '') {
            foreach (explode(',', $forwarded) as $candidate) {
                $candidate = self::validIp(trim($candidate));
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return $remote;
    }

    /** @param array<string,mixed> $server */
    public static function isHttps(array $server): bool
    {
        $https = strtolower((string)($server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }

        $remote = self::validIp($server['REMOTE_ADDR'] ?? '');
        if ($remote === null || !self::isTrustedProxy($remote)) {
            return false;
        }

        return strtolower(trim((string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
    }

    private static function validIp(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false
            ? $value
            : null;
    }

    private static function isTrustedProxy(string $ip): bool
    {
        $raw = getenv('MUCHO_TRUSTED_PROXIES');
        if ($raw === false || $raw === '') {
            $raw = $_ENV['MUCHO_TRUSTED_PROXIES'] ?? '';
        }

        if (!is_string($raw) || trim($raw) === '') {
            return false;
        }

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            if (self::ipMatchesCidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return hash_equals($cidr, $ip);
        }

        [$network, $prefixText] = explode('/', $cidr, 2);
        $network = trim($network);
        $prefix = filter_var($prefixText, FILTER_VALIDATE_INT);

        $ipBin = @inet_pton($ip);
        $networkBin = @inet_pton($network);

        if ($ipBin === false || $networkBin === false || $prefix === false) {
            return false;
        }

        if (strlen($ipBin) !== strlen($networkBin)) {
            return false;
        }

        $bits = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $bits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if (
            $fullBytes > 0 &&
            substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)
        ) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainingBits)) & 0xFF);

        return (
            (ord($ipBin[$fullBytes]) & ord($mask))
            ===
            (ord($networkBin[$fullBytes]) & ord($mask))
        );
    }
}
