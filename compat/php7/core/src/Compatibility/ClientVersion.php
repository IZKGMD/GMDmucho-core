<?php

declare (strict_types=1);
namespace MuchoCore\Compatibility;

use MuchoCore\Http\Request;
final class ClientVersion
{
    public function __construct(int $gameVersion, int $binaryVersion)
    {
        $this->gameVersion = $gameVersion;
        $this->binaryVersion = $binaryVersion;
    }
    public static function fromRequest(Request $request): self
    {
        $gameVersion = $request->postInt('gameVersion', 0);
        $binaryVersion = $request->postInt('binaryVersion', 0);
        /*
         * Unsuffixed comment endpoints are also used by genuinely old
         * clients (including early 1.x builds) without gameVersion in the
         * request body. Treat a versionless comment request as the legacy
         * floor instead of guessing GD 1.9 from the endpoint name.
         *
         * Versioned *19 routes remain explicitly identifiable below via
         * their suffix, while unversioned requests can still use the legacy
         * UDID identity path in the comment service.
         */
        if ($gameVersion === 0 && preg_match('#/(?:getgjcomments(?:19)?|uploadgjcomment(?:19)?|deletegjcomment(?:19)?|updategjlevel(?:19)?)(?:\.php)?$#i', (string) $request->path) === 1) {
            $gameVersion = preg_match('#/(?:getgjcomments19|uploadgjcomment19|deletegjcomment19|updategjlevel19)(?:\.php)?$#i', (string) $request->path) === 1 ? 19 : 1;
        }
        /*
         * Genuine GD 1.0 clients use the unsuffixed legacy database
         * endpoints and do not send a version field on these requests.
         * Cvolton-compatible servers treat this legacy generation as
         * protocol gameVersion 1 (1.0).
         */
        if ($gameVersion === 0 && preg_match('#/(?:getgjlevels|uploadgjlevel|updategjlevel|downloadgjlevel|likegjlevel|rategjlevel|updategjusername|updategjuserscore)(?:\.php)?$#i', (string) $request->path) === 1) {
            $gameVersion = 1;
        }
        /*
         * Comment requests can omit gameVersion while still targeting an
         * explicitly modern endpoint. The original 2.209 client may send
         * binaryVersion=51 on getGJComments21. Do not format its comments
         * as an unknown/legacy client (raw key-2 text).
         *
         * This inference is restricted to versioned comment endpoints;
         * old unsuffixed 1.x comment compatibility remains unchanged.
         */
        if ($gameVersion === 0) {
            $path = strtolower((string) $request->path);
            if (preg_match('#/(?:getgjcomments21|uploadgjcomment21)(?:\.php)?$#D', $path)) {
                $gameVersion = $binaryVersion >= 51 ? 22 : 21;
            } elseif (preg_match('#/(?:getgjcomments20|uploadgjcomment20|getgjaccountcomments20|uploadgjacccomment20)(?:\.php)?$#D', $path)) {
                $gameVersion = $binaryVersion >= 51 ? 22 : 20;
            }
        }
        return self::fromValues($gameVersion, $binaryVersion);
    }
    public static function fromValues(int $gameVersion, int $binaryVersion): self
    {
        return new self(max(0, $gameVersion), max(0, $binaryVersion));
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
        return (function () use (&$version) {
            $__mcMatch1 = true;
            if ($__mcMatch1 === $version >= 22) {
                return '2.2';
            }
            if ($__mcMatch1 === ($version === 21)) {
                return '2.1';
            }
            if ($__mcMatch1 === ($version === 20)) {
                return '2.0';
            }
            if ($__mcMatch1 === ($version === 11)) {
                return '1.1';
            }
            if ($__mcMatch1 === ($version === 15)) {
                return '1.5';
            }
            if ($__mcMatch1 === ($version === 1)) {
                return '1.0';
            }
            if ($__mcMatch1 === $version >= 19) {
                return '1.9';
            }
            if ($__mcMatch1 === $version > 0) {
                return '1.x';
            }
            return 'unknown';
        })();
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
    public $gameVersion;
    public $binaryVersion;
}
