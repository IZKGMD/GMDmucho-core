<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdSongEncoder
{
    private static function songLabel(mixed $value): string
    {
        // Preserve legacy empty-name and whitespace behavior while allowing
        // the full song/artist field length instead of 20-char usernames.
        $label = ProtocolText::field(trim((string)$value), 128);
        return $label !== '' ? $label : 'Player';
    }

    /**
     * Geometry Dash song objects carry a URL-encoded absolute MP3 link.
     * Accept either historical DB representation without double-encoding.
     * Reject controls, unsupported schemes and invalid URL values rather
     * than putting protocol delimiters into a client-facing response.
     */
    public static function encodeDownloadUrl(mixed $value): string
    {
        $candidate = trim((string) $value);
        if ($candidate === '' || strlen($candidate) > 2048 ||
            preg_match('/[\\x00-\\x20\\x7F]/', $candidate)) {
            return '';
        }

        $url = rawurldecode($candidate);
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\\r\\n#~|]/', $url)) {
            return '';
        }

        // Existing songs may already store an encoded Newgrounds/CDN link.
        return $url === $candidate ? rawurlencode($url) : $candidate;
    }

    public function encode(array $song): string
    {
        $data = [
            1, (int)$song['id'],
            // Song titles and artist names have their own protocol sizes;
            // the username formatter wrongly truncated both to 20 bytes.
            2, self::songLabel($song['name'] ?? ''),
            3, (int)($song['author_id'] ?? 0),
            4, self::songLabel($song['author_name'] ?? ''),
            5, round((float)($song['size'] ?? 0), 2),
            6, (string)($song['youtube_video_id'] ?? ''),
            10, self::encodeDownloadUrl($song['download_url'] ?? ''),
            7, (string)($song['youtube_channel_id'] ?? ''),
            // GD field 8 is "disabled", not "verified".
            8, (int)($song['is_disabled'] ?? 0),
        ];

        return implode('~|~', $data);
    }
}
