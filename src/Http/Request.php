<?php

declare(strict_types=1);

namespace MuchoCore\Http;

final readonly class Request
{
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $post,
        public array $server,
    ) {}

    public static function fromGlobals(): self
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        return new self(
            method: strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: is_string($path) ? $path : '/',
            query: $_GET,
            post: $_POST,
            server: $_SERVER,
        );
    }

    public function postString(string $key): string
    {
        $value = $this->post[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    public function postInt(string $key, int $default = 0): int
    {
        $value = $this->post[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int)$value;
        }

        return $default;
    }

    public function clientVersion(): \MuchoCore\Compatibility\ClientVersion
    {
        return \MuchoCore\Compatibility\ClientVersion::fromRequest($this);
    }

    public function gdCredential(): string
    {
        $version = $this->clientVersion();

        if ($version->usesGjp2()) {
            return $this->postString('gjp2')
                ?: $this->postString('gjp');
        }

        return $this->postString('gjp')
            ?: $this->postString('gjp2');
    }

    public function clientIp(): string
    {
        $remote = (string)($this->server['REMOTE_ADDR'] ?? '');

        $trustedProxy = in_array(
            $remote,
            ['127.0.0.1', '::1'],
            true
        );

        if ($trustedProxy) {
            // Cloudflare Tunnel: prefer the real client IP when the proxy is trusted.
            $cf = $this->server['HTTP_CF_CONNECTING_IP'] ?? '';

            if (
                is_string($cf) &&
                filter_var($cf, FILTER_VALIDATE_IP) !== false
            ) {
                return $cf;
            }

            $forwarded = $this->server['HTTP_X_FORWARDED_FOR'] ?? '';

            if (is_string($forwarded) && $forwarded !== '') {
                foreach (explode(',', $forwarded) as $candidate) {
                    $candidate = trim($candidate);

                    if (
                        filter_var($candidate, FILTER_VALIDATE_IP) !== false
                    ) {
                        return $candidate;
                    }
                }
            }
        }

        return filter_var($remote, FILTER_VALIDATE_IP) !== false
            ? $remote
            : '0.0.0.0';
    }
}
