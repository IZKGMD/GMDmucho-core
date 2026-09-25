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

        if (!$isPrivateProxy) {
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
}
