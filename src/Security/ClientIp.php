<?php

declare(strict_types=1);

namespace MuchoCore\Security;

/*
 * MuchoCore Client IP resolver
 * Copyright (C) 2026 IZK
 */
final class ClientIp
{
    public static function detect(array $server): string
    {
        $remote = self::validIp($server['REMOTE_ADDR'] ?? '');

        if ($remote === '') {
            return '0.0.0.0';
        }

        if (!self::trustProxyHeaders($server)) {
            return $remote;
        }

        /*
         * Never trust forwarded headers from a public peer.
         * Proxy headers are accepted only when PHP was reached from a
         * private/loopback/reserved address and the operator explicitly
         * enabled proxy-header trust.
         */
        $public = filter_var(
            $remote,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($public !== false) {
            return $remote;
        }

        $cf = self::validIp($server['HTTP_CF_CONNECTING_IP'] ?? '');

        if ($cf !== '') {
            return $cf;
        }

        $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? '';

        if (is_string($forwarded)) {
            foreach (explode(',', $forwarded) as $candidate) {
                $candidate = self::validIp(trim($candidate));

                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return $remote;
    }

    private static function trustProxyHeaders(array $server): bool
    {
        $value = $server['MUCHO_TRUST_PROXY_HEADERS']
            ?? getenv('MUCHO_TRUST_PROXY_HEADERS');

        return in_array(
            strtolower(trim((string)$value)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    private static function validIp(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false
            ? $value
            : '';
    }
}
