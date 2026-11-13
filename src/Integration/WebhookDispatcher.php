<?php

declare(strict_types=1);

namespace MuchoCore\Integration;

use MuchoCore\Core\Environment;
use RuntimeException;

final class WebhookDispatcher
{
    private const MAX_PAYLOAD_BYTES = 65536;
    private const MAX_RESPONSE_BYTES = 65536;

    /**
     * Queued webhooks may contain player-supplied names and identifiers.
     * The destination is operator-configured, but must never target an
     * internal service or permit credentials in URLs.
     */
    public static function safeDestination(string $url): bool
    {
        $parts = parse_url($url);
        if (
            $url === '' || strlen($url) > 2048 ||
            filter_var($url, FILTER_VALIDATE_URL) === false ||
            !is_array($parts) ||
            strtolower((string)($parts['scheme'] ?? '')) !== 'https' ||
            isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['fragment']) ||
            (isset($parts['port']) && (int)$parts['port'] !== 443)
        ) {
            return false;
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        if (
            $host === '' || str_contains($host, ':') ||
            preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host) !== 1
        ) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::publicAddress($host);
        }
        return str_contains($host, '.') &&
            preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid)$/D', $host) !== 1;
    }

    public static function publicAddress(string $address): bool
    {
        // A public IPv4 address is needed to pin the actual cURL connection.
        // Do not resolve the host a second time after this check.
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    public static function safeHeaders(string $event, string $requestId): bool
    {
        return preg_match('/^[A-Za-z0-9_.-]{1,96}$/D', $event) === 1 &&
            strlen($requestId) <= 128 &&
            preg_match('/[\x00-\x1F\x7F]/', $requestId) !== 1;
    }

    public function enabledFor(string $event): bool
    {
        $url = trim((string)(Environment::get('MUCHO_WEBHOOK_URL', '') ?? ''));
        if ($url === '') {
            return false;
        }

        $raw = trim((string)(Environment::get('MUCHO_WEBHOOK_EVENTS', '*') ?? '*'));
        if ($raw === '' || $raw === '*') {
            return true;
        }

        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $allowed) {
            if ($allowed === $event) {
                return true;
            }
        }
        return false;
    }

    public function dispatch(
        string $event,
        array $data,
        string $requestId = ''
    ): bool {
        $url = trim((string)(Environment::get('MUCHO_WEBHOOK_URL', '') ?? ''));
        if ($url === '') {
            return true;
        }
        if (!self::safeDestination($url)) {
            throw new RuntimeException('Webhook destination must be public HTTPS on port 443.');
        }
        if (!$this->enabledFor($event)) {
            return true;
        }
        if (!self::safeHeaders($event, $requestId)) {
            throw new RuntimeException('Invalid queued webhook header metadata.');
        }

        $secret = (string)(Environment::get('MUCHO_WEBHOOK_SECRET', '') ?? '');
        $payload = json_encode([
            'event' => $event,
            'request_id' => $requestId,
            'sent_at' => gmdate('c'),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new RuntimeException('Webhook payload exceeds the delivery limit.');
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: MuchoCore-Webhook/1.0',
            'X-MuchoCore-Event: ' . $event,
        ];
        if ($requestId !== '') {
            $headers[] = 'X-MuchoCore-Request-Id: ' . $requestId;
        }
        if ($secret !== '') {
            $headers[] = 'X-MuchoCore-Signature: sha256=' .
                hash_hmac('sha256', $payload, $secret);
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for secure webhook delivery.');
        }

        $host = (string)parse_url($url, PHP_URL_HOST);
        $addresses = gethostbynamel($host);
        if (!is_array($addresses) || $addresses === []) {
            throw new RuntimeException('Webhook DNS lookup failed.');
        }
        foreach ($addresses as $address) {
            if (!self::publicAddress((string)$address)) {
                throw new RuntimeException('Webhook DNS includes a non-public address.');
            }
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize webhook transport.');
        }
        $received = 0;
        try {
            if (!curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_NOPROXY => '*',
                CURLOPT_RESOLVE => [$host . ':443:' . $addresses[0]],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$received): int {
                    $received += strlen($chunk);
                    return $received <= self::MAX_RESPONSE_BYTES ? strlen($chunk) : 0;
                },
            ])) {
                throw new RuntimeException('Unable to configure secure webhook delivery.');
            }
            $ok = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            return $ok !== false && $status >= 200 && $status < 300;
        } finally {
            curl_close($curl);
        }
    }
}
