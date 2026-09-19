<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdSongEncoder
{
    public function encode(array $song): string
    {
        $data = [
            1, $song['id'],
            2, $song['name'],
            3, $song['author_id'],
            4, $song['author_name'],
            5, round((float)$song['size'], 2),
            6, $song['youtube_video_id'] ?? '',
            10, $song['download_url'],
            7, $song['youtube_channel_id'] ?? '',
            8, (int)($song['is_verified'] ?? 0)
        ];

        return implode('~|~', $data);
    }
}
