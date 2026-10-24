<?php

declare (strict_types=1);
namespace MuchoCore\Core;

final class RequestContext
{
    public function __construct(string $requestId, float $startedAt)
    {
        $this->requestId = $requestId;
        $this->startedAt = $startedAt;
    }
    public static function create(): self
    {
        return new self(bin2hex(random_bytes(8)), microtime(true));
    }
    public function durationMs(): float
    {
        return (microtime(true) - $this->startedAt) * 1000;
    }
    public $requestId;
    public $startedAt;
}
