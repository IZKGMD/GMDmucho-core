<?php

declare(strict_types=1);

namespace MuchoCore\Integration;

use MuchoCore\Core\Environment;

final class WebhookDispatcher
{
    public static function acceptsUrl(string $url): bool
    {
        // Accept only HTTP(S); do not trust stream-wrapper schemes,
        // embedded credentials, fragments or control characters.
        if ($url === '' || strlen($url) > 2048 ||
            preg_match('/[\\x00-\\x20\\x7F]/', $url) === 1 ||
            filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            && isset($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
    }

    public function enabledFor(string $event): bool
    {
        $url = trim((string)(
            Environment::get('MUCHO_WEBHOOK_URL', '')
        ));

        if (!self::acceptsUrl($url)) {
            return false;
        }

        $raw = trim((string)(
            Environment::get('MUCHO_WEBHOOK_EVENTS', '*')
        ));

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
        if (!$this->enabledFor($event)) {
            return true;
        }

        if ($event === '' || strlen($event) > 128 ||
            strlen($requestId) > 128 ||
            preg_match('/[\\x00-\\x1F\\x7F]/', $event . $requestId) === 1) {
            // Never let plugin-supplied identifiers alter HTTP headers.
            return false;
        }

        $url = trim((string)(Environment::get('MUCHO_WEBHOOK_URL', '') ?? ''));
        $secret = (string)(
            Environment::get('MUCHO_WEBHOOK_SECRET', '')
        );

        $payload = json_encode([
            'event' => $event,
            'request_id' => $requestId,
            'sent_at' => gmdate('c'),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

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

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 5,
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $payload,
            ],
        ]);

        // Never buffer an untrusted webhook response body or follow
        // redirects that could forward the signed payload to another host.
        $stream = @fopen($url, 'rb', false, $context);
        if (!is_resource($stream)) {
            return false;
        }

        try {
            $metadata = stream_get_meta_data($stream);
            $responseHeaders = $metadata['wrapper_data'] ?? [];
            $status = is_array($responseHeaders) ? (string)($responseHeaders[0] ?? '') : '';
        } finally {
            fclose($stream);
        }

        return preg_match('/^HTTP\\/\\S+\\s+2\\d\\d(?:\\s|$)/', $status) === 1;
    }
}
