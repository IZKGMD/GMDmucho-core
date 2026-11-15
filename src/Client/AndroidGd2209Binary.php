<?php

declare(strict_types=1);

namespace MuchoCore\Client;

use RuntimeException;

/**
 * Exact-build ARM64 Android GD native API patcher.
 * Modifies only NUL-terminated API URL strings in the original ELF.
 * It does not replace non-API links, alter ELF instructions or resize the library.
 */
final class AndroidGd2209Binary
{
    public const LIBRARY_SHA256 = '46b0e17f6323979f5cd415a8a241269ac384f796a5ef35e668dfe7af3346d0c1';
    public const LIBRARY_SIZE = 22277128;
    public const ENDPOINTS = 113;

    public static function supports(string $native): bool
    {
        return strlen($native) === self::LIBRARY_SIZE &&
            hash('sha256', $native) === self::LIBRARY_SHA256;
    }

    /**
     * Accept a different GD 2.209 Android ELF only when the whole native
     * endpoint table matches the audited 2.209 layout. A hash mismatch alone
     * is not a reason to reject a legitimately repackaged/variant APK.
     *
     * This is NOT a universal APK or version detector. Unknown layouts and
     * already-patched libraries continue to fail closed.
     */
    public static function isStructurallyCompatible(string $native): bool
    {
        if (strlen($native) < 1024 * 1024 ||
            !str_starts_with($native, "\x7fELF") ||
            ord($native[5]) !== 1) {
            return false;
        }

        $elfClass = ord($native[4]);
        $machine = unpack('v', substr($native, 18, 2))[1];
        if (!(($elfClass === 2 && $machine === 183) ||
              ($elfClass === 1 && $machine === 40))) {
            return false;
        }

        $pattern = '~https?://(?:www\\.boomlings\\.com|boomlings\\.com)/(?:database|databas)/[A-Za-z0-9._/-]+\\.php(?=\\x00)~';
        if (preg_match_all($pattern, $native, $found, PREG_OFFSET_CAPTURE) !== self::ENDPOINTS) {
            return false;
        }

        $paths = [];
        foreach ($found[0] as [$url, $offset]) {
            if ($offset > 0 && $native[$offset - 1] !== "\0") {
                return false;
            }
            $path = substr($url, strrpos($url, '/') + 1);
            $paths[$path] = true;
        }
        return count($paths) >= 80 &&
            isset($paths['getGJNotifications.php'], $paths['getGJThreadInvites.php']);
    }

    public static function resembles2209(string $native): bool
    {
        return str_starts_with($native, "\x7fELF") &&
            str_contains($native, 'getGJNotifications.php') &&
            str_contains($native, 'getGJThreadInvites.php');
    }

    /** @return array{string,int,list<string>} */
    public static function patchBytes(string $native, string $serverUrl, ?int $expected = null): array
    {
        $parsed = parse_url($serverUrl);
        if (!is_array($parsed) ||
            ($parsed['scheme'] ?? '') !== 'https' ||
            isset($parsed['port']) ||
            !preg_match('/^[A-Za-z0-9.-]+$/D', (string)($parsed['host'] ?? ''))) {
            throw new RuntimeException('GD 2.209 Android requires an HTTPS hostname without a custom port.');
        }
        $host = (string)$parsed['host'];
        $regex = '~https?://(?:www\\.boomlings\\.com|boomlings\\.com)/(?:database|databas)/[A-Za-z0-9._/-]+\\.php(?=\\x00)~';
        $matches = [];
        preg_match_all($regex, $native, $matches, PREG_OFFSET_CAPTURE);
        $count = 0;
        $paths = [];
        foreach ($matches[0] as [$url, $position]) {
            // Match only complete ASCII string entries, not unrelated text
            // with a URL fragment in the middle.
            if ($position > 0 && $native[$position - 1] !== "\0") {
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
            $native = substr_replace(
                $native,
                $new . str_repeat("\0", strlen($url) - strlen($new)),
                $position,
                strlen($url)
            );
            $paths[] = $path;
            $count++;
        }
        if ($count === 0 || ($expected !== null && $count !== $expected)) {
            throw new RuntimeException(
                'Native GD 2.209 API mismatch; expected ' . ($expected ?? 'some') .
                ' URLs and found ' . $count . '. Refusing to patch an unknown build.'
            );
        }
        if (preg_match($regex, $native) === 1) {
            throw new RuntimeException('Some original GD 2.209 API URLs were not patched.');
        }
        return [$native, $count, array_values(array_unique($paths))];
    }
}
