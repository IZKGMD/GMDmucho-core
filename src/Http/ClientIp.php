<?php

declare(strict_types=1);

namespace MuchoCore\Http;

use MuchoCore\Core\Environment;

final class ClientIp
{
    /**
     * Cloudflare's current public proxy ranges. These are used only to
     * decide whether CF-Connecting-IP may be trusted; an ordinary internet
     * client cannot spoof the TCP source address of a Cloudflare edge.
     * Keep explicit MUCHO_TRUSTED_PROXY_CIDRS for private/self-hosted proxies.
     */
    private const LOCAL_PROXY_CIDRS = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    private const CLOUDFLARE_CIDRS = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
        '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
        '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
        '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * Resolve the original client IP behind Caddy/Cloudflare.
     *
     * Forwarded headers are only trusted when the direct peer is an
     * explicitly trusted proxy, Cloudflare edge, or local/private proxy.
     */
    public static function resolve(array $server): string
    {
        $remote = trim((string)($server['REMOTE_ADDR'] ?? ''));

        if ($remote === '' || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }

        $trustedProxyCidrs = self::trustedProxyCidrs();
        $explicitTrustedProxy = $trustedProxyCidrs !== []
            && self::ipInAnyCidr($remote, $trustedProxyCidrs);
        $cloudflareTrustedProxy = self::ipInAnyCidr(
            $remote,
            self::CLOUDFLARE_CIDRS
        );
        $privateProxy = self::ipInAnyCidr(
            $remote,
            self::LOCAL_PROXY_CIDRS
        );

        $privateProxyFallback =
            $trustedProxyCidrs === [] &&
            $privateProxy;

        if (
            !$explicitTrustedProxy &&
            !$cloudflareTrustedProxy &&
            !$privateProxyFallback
        ) {
            return $remote;
        }

        if ($cloudflareTrustedProxy) {
            $cloudflareClient = trim(
                (string)($server['HTTP_CF_CONNECTING_IP'] ?? '')
            );

            if (
                $cloudflareClient !== '' &&
                filter_var($cloudflareClient, FILTER_VALIDATE_IP) !== false
            ) {
                return $cloudflareClient;
            }

            // A Cloudflare peer without a valid canonical client address must
            // not fall back to X-Forwarded-For: its leftmost entries may have
            // originated with the requester.
            return $remote;
        }

        /*
         * Walk X-Forwarded-For from the nearest hop backwards. The leftmost
         * address is attacker-controlled when a proxy appends to an incoming
         * XFF header instead of replacing it. Stop at the first untrusted hop.
         *
         * Private-peer fallback trusts only the immediate peer, not arbitrary
         * private addresses listed in XFF. Additional trusted hops require an
         * explicit MUCHO_TRUSTED_PROXY_CIDRS allow-list.
         */
        $forwarded = trim((string)($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $chain = explode(',', $forwarded);
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                $candidate = trim($chain[$i]);

                // A malformed nearest hop makes the chain untrustworthy.
                if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                    return $remote;
                }

                if ($explicitTrustedProxy && self::ipInAnyCidr($candidate, $trustedProxyCidrs)) {
                    continue;
                }

                return $candidate;
            }
        }

        return $remote;
    }

    /** @return list<string> */
    private static function trustedProxyCidrs(): array
    {
        $raw = Environment::get('MUCHO_TRUSTED_PROXY_CIDRS', '') ?? '';
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
