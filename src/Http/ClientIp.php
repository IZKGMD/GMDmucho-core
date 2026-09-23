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

        foreach ([
            'HTTP_CF_CONNECTING_IP',
            'HTTP_TRUE_CLIENT_IP',
        ] as $header) {
            $candidate = trim((string)($server[$header] ?? ''));

            if (
                $candidate !== '' &&
                filter_var($candidate, FILTER_VALIDATE_IP) !== false
            ) {
                return $candidate;
            }
        }

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
