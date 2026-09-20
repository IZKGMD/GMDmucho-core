<?php

declare(strict_types=1);

namespace MuchoCore\Http;

use MuchoCore\Security\ClientIp;

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
        return ClientIp::detect($this->server);
    }

}
