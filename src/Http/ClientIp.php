<?php

declare(strict_types=1);

namespace MuchoCore\Http;

final class ClientIp
{
    /**
     * Resolve the original client IP behind Caddy/Cloudflare.
     *
     * Forwarded headers are only trusted when the direct peer is a
     * private/reserved address, which matches the container proxy path.
     */
    public static function resolve(array $server): string
    {
        $remote = trim((string)($server['REMOTE_ADDR'] ?? ''));

        if ($remote === '' || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }

        $isPrivateProxy = filter_var(
            $remote,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;

        $trustedProxyCidrs = self::trustedProxyCidrs();
        if ($trustedProxyCidrs !== []) {
            if (!self::ipInAnyCidr($remote, $trustedProxyCidrs)) {
                return $remote;
            }
        } elseif (!$isPrivateProxy) {
            return $remote;
        }

        /*
         * Do not trust CDN-specific client-IP headers here. When the app sits
         * behind Caddy, an attacker can otherwise inject CF-Connecting-IP or
         * similar headers and evade IP-based rate limits. Caddy controls and
         * sanitizes X-Forwarded-For before proxying to PHP.
         *
         * If a CDN is placed in front of Caddy, configure that proxy as a
         * trusted proxy at the Caddy layer instead of trusting its headers in
         * application code.
         */
        $forwarded = trim((string)($server['HTTP_X_FORWARDED_FOR'] ?? ''));

        if ($forwarded !== '') {
            foreach (explode(',', $forwarded) as $candidate) {
                $candidate = trim($candidate);

                if (
                    $candidate !== '' &&
                    filter_var($candidate, FILTER_VALIDATE_IP) !== false
                ) {
                    return $candidate;
                }
            }
        }

        return $remote;
    }

    /** @return list<string> */
    private static function trustedProxyCidrs(): array
    {
        $raw = $_ENV['MUCHO_TRUSTED_PROXY_CIDRS']
            ?? $_SERVER['MUCHO_TRUSTED_PROXY_CIDRS']
            ?? getenv('MUCHO_TRUSTED_PROXY_CIDRS')
            ?? '';
        $tokens = preg_split('/[\\s,]+/', trim((string)$raw)) ?: [];
        $result = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') continue;
            if (!str_contains($token, '/')) {
                $token .= (filter_var($token, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) ? '/128' : '/32';
            }
            [$network, $prefix] = array_pad(explode('/', $token, 2), 2, '');
            if (filter_var($network, FILTER_VALIDATE_IP) === false || !ctype_digit($prefix)) continue;
            $max = filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;
            $prefixInt = (int)$prefix;
            if ($prefixInt < 0 || $prefixInt > $max) continue;
            $result[] = $network . '/' . $prefixInt;
        }
        return array_values(array_unique($result));
    }

    /** @param list<string> $cidrs */
    private static function ipInAnyCidr(string $ip, array $cidrs): bool
    {
        $packedIp = inet_pton($ip);
        if ($packedIp === false) return false;
        foreach ($cidrs as $cidr) {
            [$network, $prefix] = explode('/', $cidr, 2);
            $packedNetwork = inet_pton($network);
            if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedIp)) continue;
            $prefixInt = (int)$prefix;
            $fullBytes = intdiv($prefixInt, 8);
            $remainingBits = $prefixInt % 8;
            if ($fullBytes > 0 && substr($packedIp, 0, $fullBytes) !== substr($packedNetwork, 0, $fullBytes)) continue;
            if ($remainingBits === 0) return true;
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            if ((ord($packedIp[$fullBytes]) & $mask) === (ord($packedNetwork[$fullBytes]) & $mask)) return true;
        }
        return false;
    }
}
