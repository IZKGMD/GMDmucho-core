<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdSongEncoder
{
    public function encode(array $song): string
    {
        $data = [
            1, (int)$song['id'],
            // Song titles and artist names have their own protocol sizes;
            // the username formatter wrongly truncated both to 20 bytes.
            2, ProtocolText::field((string)($song['name'] ?? ''), 128),
            3, (int)($song['author_id'] ?? 0),
            4, ProtocolText::field((string)($song['author_name'] ?? ''), 128),
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
