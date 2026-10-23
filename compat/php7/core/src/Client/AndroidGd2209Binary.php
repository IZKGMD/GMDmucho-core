<?php

declare (strict_types=1);
namespace MuchoCore\Client;

use RuntimeException;
/**
 * Exact-build ARM64 Android GD native API patcher.
 * Modifies only NUL-terminated API URL strings in the original ELF.
 * It does not replace non-API links, alter ELF instructions or resize the library.
 */
final class AndroidGd2209Binary
{
    const LIBRARY_SHA256 = '46b0e17f6323979f5cd415a8a241269ac384f796a5ef35e668dfe7af3346d0c1';
    const LIBRARY_SIZE = 22277128;
    const ENDPOINTS = 113;
    public static function supports(string $native): bool
    {
        return strlen($native) === self::LIBRARY_SIZE && hash('sha256', $native) === self::LIBRARY_SHA256;
    }
    public static function resembles2209(string $native): bool
    {
        return str_starts_with($native, "ELF") && str_contains($native, 'getGJNotifications.php') && str_contains($native, 'getGJThreadInvites.php');
    }
    /** @return array{string,int,list<string>} */
    public static function patchBytes(string $native, string $serverUrl, $expected = null): array
    {
        $parsed = parse_url($serverUrl);
        if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== 'https' || isset($parsed['port']) || !preg_match('/^[A-Za-z0-9.-]+$/D', (string) ($parsed['host'] ?? ''))) {
            throw new RuntimeException('GD 2.209 Android requires an HTTPS hostname without a custom port.');
        }
        $host = (string) $parsed['host'];
        $regex = '~https?://(?:www\.boomlings\.com|boomlings\.com)/(?:database|databas)/[A-Za-z0-9._/-]+\.php(?=\x00)~';
        $matches = [];
        preg_match_all($regex, $native, $matches, PREG_OFFSET_CAPTURE);
        $count = 0;
        $paths = [];
        foreach ($matches[0] as list($url, $position)) {
            // Match only complete ASCII string entries, not unrelated text
            // with a URL fragment in the middle.
            if ($position > 0 && $native[$position - 1] !== "\x00") {
                continue;
            }
            $path = substr($url, strpos($url, '/', strpos($url, '://') + 3));
            if (str_starts_with($path, '/databas/')) {
                $path = '/database/' . substr($path, strlen('/databas/'));
            }
            $new = 'https://' . $host . $path;
            if (strlen($new) > strlen($url)) {
                $new = 'https://' . $host . '/a/' . substr($path, strlen('/database/'));
            }
            if (strlen($new) > strlen($url)) {
                $new = 'https://' . $host . '/' . substr($path, strlen('/database/'));
            }
            if (strlen($new) > strlen($url)) {
                throw new RuntimeException('GD 2.209 server domain is too long for an ELF URL slot.');
            }
            $native = substr_replace($native, $new . str_repeat("\x00", strlen($url) - strlen($new)), $position, strlen($url));
            $paths[] = $path;
            $count++;
        }
        if ($count === 0 || $expected !== null && $count !== $expected) {
            throw new RuntimeException('Native GD 2.209 API mismatch; expected ' . ($expected ?? 'some') . ' URLs and found ' . $count . '. Refusing to patch an unknown build.');
        }
        if (preg_match($regex, $native) === 1) {
            throw new RuntimeException('Some original GD 2.209 API URLs were not patched.');
        }
        return [$native, $count, array_values(array_unique($paths))];
    }
}
