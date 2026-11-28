<?php

declare(strict_types=1);

namespace MuchoCore\Client;

use RuntimeException;

/**
 * Build-specific support for a user-supplied, third-party GD 2.2.144 ARM64 APK.
 * Static binary compatibility does not establish APK safety or runtime behavior.
 * Do not treat this fingerprint as a universal 2.2.144 / any-APK signature.
 */
final class AndroidGd22144Binary
{
    public const APK_SHA256 = '3c7bf689fc25d21856e053634581ef8d452c44cf854ddefebf53aae6eabfe558';
    public const LIBRARY_SHA256 = 'dda3752ab3a912fd2293561e157ecc98eca799056abd9223a331c1c514175df7';
    public const LIBRARY_SIZE = 19077352;
    public const ENDPOINTS = 64;
    public const BOOMLINGS_ENDPOINTS = 61;
    public const GEOMETRYDASH_ENDPOINTS = 3;

    public static function supports(string $native): bool
    {
        return strlen($native) === self::LIBRARY_SIZE &&
            hash_equals(self::LIBRARY_SHA256, hash('sha256', $native));
    }

    public static function resembles22144(string $native): bool
    {
        return str_starts_with($native, "\x7fELF") &&
            str_contains($native, 'getGJLevels21.php') &&
            str_contains($native, 'uploadMPComment.php');
    }

    /**
     * Patch exact-length NUL-terminated .php URL slots, including the three
     * multiplayer routes hosted on geometrydash.com. Callers must validate
     * supports() and whole-APK fingerprint before trusting the layout.
     *
     * @return array{string,int,list<string>}
     */
    public static function patchBytes(string $native, string $serverUrl, ?int $expected = null): array
    {
        $parsed = parse_url($serverUrl);
        if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== 'https' ||
            isset($parsed['port']) || isset($parsed['path']) ||
            !preg_match('/^[A-Za-z0-9.-]+$/D', (string)($parsed['host'] ?? ''))) {
            throw new RuntimeException('Android GD 2.2.144 requires an HTTPS hostname without port or path.');
        }
        $host = (string)$parsed['host'];
        $pattern = '~https?://(?:www\\.)?(?:boomlings\\.com|geometrydash\\.com)/(?:database|databas)/[A-Za-z0-9._/-]+\\.php(?=\\x00)~';
        preg_match_all($pattern, $native, $hits, PREG_OFFSET_CAPTURE);
        $expected ??= self::ENDPOINTS;
        if (count($hits[0]) !== $expected) {
            throw new RuntimeException('Android GD 2.2.144 API mismatch: expected ' .
                $expected . ' URL slots, found ' . count($hits[0]) . '.');
        }
        $matchedHosts = ['boomlings.com' => 0, 'geometrydash.com' => 0];
        $routes = [];
        $patched = $native;
        foreach ($hits[0] as [$original, $offset]) {
            if ($offset > 0 && $native[$offset - 1] !== "\0") {
                throw new RuntimeException('Android GD 2.2.144 URL slot is not NUL-framed.');
            }
            $parsedUrl = parse_url($original);
            if (!is_array($parsedUrl) || !isset($parsedUrl['host'], $parsedUrl['path'])) {
                throw new RuntimeException('Malformed GD 2.2.144 URL slot.');
            }
            $originalHost = strtolower((string)$parsedUrl['host']);
            $key = str_starts_with($originalHost, 'www.') ? substr($originalHost, 4) : $originalHost;
            $matchedHosts[$key]++;
            $path = (string)$parsedUrl['path'];
            if (str_starts_with($path, '/databas/')) {
                $path = '/database/' . substr($path, strlen('/databas/'));
            }
            $replacement = 'https://' . $host . $path;
            if (strlen($replacement) > strlen($original)) {
                $replacement = 'https://' . $host . '/a/' . substr($path, strlen('/database/'));
            }
            if (strlen($replacement) > strlen($original)) {
                throw new RuntimeException('GD 2.2.144 server hostname too long for a native URL slot.');
            }
            $patched = substr_replace(
                $patched,
                $replacement . str_repeat("\0", strlen($original) - strlen($replacement)),
                $offset,
                strlen($original)
            );
            $routes[] = $path;
        }
        if ($expected === self::ENDPOINTS &&
            ($matchedHosts['boomlings.com'] !== self::BOOMLINGS_ENDPOINTS ||
             $matchedHosts['geometrydash.com'] !== self::GEOMETRYDASH_ENDPOINTS ||
             count(array_unique($routes)) !== self::ENDPOINTS)) {
            throw new RuntimeException('GD 2.2.144 endpoint host distribution/layout has changed.');
        }
        if (strlen($patched) !== strlen($native) || preg_match($pattern, $patched) === 1) {
            throw new RuntimeException('GD 2.2.144 rewrite changed ELF size or left original API URLs.');
        }
        return [$patched, count($hits[0]), array_values(array_unique($routes))];
    }
}
