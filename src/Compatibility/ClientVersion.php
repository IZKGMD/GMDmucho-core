<?php

declare(strict_types=1);

namespace MuchoCore\Compatibility;

use MuchoCore\Http\Request;

final readonly class ClientVersion
{
    public function __construct(
        public int $gameVersion,
        public int $binaryVersion
    ) {}

    public static function fromRequest(Request $request): self
    {
        return self::fromValues(
            $request->postInt('gameVersion', 0),
            $request->postInt('binaryVersion', 0)
        );
    }

    public static function fromValues(
        int $gameVersion,
        int $binaryVersion
    ): self {
        return new self(
            max(0, $gameVersion),
            max(0, $binaryVersion)
        );
    }

    public function family(): string
    {
        return match (true) {
            $this->gameVersion >= 22 => '2.2',
            $this->gameVersion === 21 => '2.1',
            $this->gameVersion === 20 => '2.0',
            $this->gameVersion >= 19 => '1.9',
            $this->gameVersion > 0 => '1.x',
            default => 'unknown',
        };
    }

    public function label(): string
    {
        if ($this->gameVersion <= 0) {
            return 'unknown';
        }

        if ($this->binaryVersion <= 0) {
            return $this->family();
        }

        return $this->family() . '/' . $this->binaryVersion;
    }

    public function isKnown(): bool
    {
        return $this->gameVersion > 0;
    }

    public function is2_2OrNewer(): bool
    {
        return $this->gameVersion >= 22;
    }

    public function is2_1OrOlder(): bool
    {
        return $this->gameVersion > 0 && $this->gameVersion <= 21;
    }

    public function usesGjp2(): bool
    {
        return $this->is2_2OrNewer();
    }
}
