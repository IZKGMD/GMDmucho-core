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
        $gameVersion = $request->postInt('gameVersion', 0);
        $binaryVersion = $request->postInt('binaryVersion', 0);

        /*
         * Some genuine GD 1.9 endpoints do not include gameVersion or
         * binaryVersion in their POST body. The endpoint suffix itself is
         * authoritative for these versioned legacy routes.
         */
        if (
            $gameVersion === 0 &&
            preg_match(
                '#/(?:getgjcomments19|uploadgjcomment19|deletegjcomment19|updategjlevel19)(?:\\.php)?$#i',
                (string)$request->path
            ) === 1
        ) {
            $gameVersion = 19;
        }

        return self::fromValues(
            $gameVersion,
            $binaryVersion
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

    /**
     * Geometry Dash 2.0 binaries above 27 use the 2.1 protocol generation.
     * This matches the historical server/client compatibility boundary.
     */
    public function effectiveGameVersion(): int
    {
        if ($this->gameVersion === 20 && $this->binaryVersion > 27) {
            return 21;
        }

        return $this->gameVersion;
    }

    public function family(): string
    {
        $version = $this->effectiveGameVersion();

        return match (true) {
            $version >= 22 => '2.2',
            $version === 21 => '2.1',
            $version === 20 => '2.0',
            $version >= 19 => '1.9',
            $version > 0 => '1.x',
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
        return $this->effectiveGameVersion() >= 22;
    }

    public function is2_1OrOlder(): bool
    {
        $version = $this->effectiveGameVersion();
        return $version > 0 && $version <= 21;
    }

    public function usesGjp2(): bool
    {
        return $this->effectiveGameVersion() >= 22;
    }
}
