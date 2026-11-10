<?php

declare (strict_types=1);
namespace MuchoCore\Protocol;

final class GdSongEncoder
{
    private static function songLabel($value): string
    {
        $label = ProtocolText::field(trim((string) $value), 128);
        return $label !== '' ? $label : 'Player';
    }

    public static function encodeDownloadUrl($value): string
    {
        $candidate = trim((string) $value);
        if ($candidate === '' || strlen($candidate) > 2048 ||
            preg_match('/[\\x00-\\x20\\x7F]/', $candidate)) {
            return '';
        }
        $url = rawurldecode($candidate);
        $parts = parse_url($url);
        if (!is_array($parts) ||
            !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true) ||
            empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
            preg_match('/[\\r\\n#~|]/', $url)) {
            return '';
        }
        return preg_match('~^https?%3A%2F%2F~iD', $candidate) === 1
            ? $candidate : rawurlencode($candidate);
    }

    public function encode(array $song): string
    {
        $data = [
            1,
            (int) $song['id'],
            2,
            self::songLabel($song['name'] ?? ''),
            3,
            (int) ($song['author_id'] ?? 0),
            4,
            self::songLabel($song['author_name'] ?? ''),
            5,
            round((float) ($song['size'] ?? 0), 2),
            6,
            (string) ($song['youtube_video_id'] ?? ''),
            10,
            self::encodeDownloadUrl($song['download_url'] ?? ''),
            7,
            (string) ($song['youtube_channel_id'] ?? ''),
            // GD field 8 is "disabled", not "verified".
            8,
            (int) ($song['is_disabled'] ?? 0),
        ];
        return implode('~|~', $data);
    }
}
