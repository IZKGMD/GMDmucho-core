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
            10, (string)($song['download_url'] ?? ''),
            7, (string)($song['youtube_channel_id'] ?? ''),
            // GD field 8 is "disabled", not "verified".
            8, (int)($song['is_disabled'] ?? 0),
        ];

        return implode('~|~', $data);
    }
}
