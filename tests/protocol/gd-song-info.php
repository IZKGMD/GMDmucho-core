<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Protocol\GdSongEncoder;
use MuchoCore\Protocol\ProtocolText;

$check = static function (bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException('GD song protocol: ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
};

$encoder = new GdSongEncoder();
$title = 'MuchoCore Acceptance Fixture';
$artist = 'Composer with a long display name';
$wire = $encoder->encode([
    'id' => 99999001,
    'name' => $title,
    'author_id' => 20,
    'author_name' => $artist,
    'size' => 1.5,
    'download_url' => 'https://example.invalid/acceptance.mp3',
    'youtube_video_id' => '',
    'youtube_channel_id' => '',
    'is_disabled' => 0,
]);

$check(str_contains($wire, '2~|~' . $title . '~|~3~|~20'),
    'full song title survives serialization without username truncation');
$check(str_contains($wire, '4~|~' . $artist . '~|~5~|~1.5'),
    'full artist name survives serialization without username truncation');

$unsafe = $encoder->encode([
    'id' => 5, 'name' => "Song:#|~\r\nName",
    'author_name' => 'Artist:#|~', 'download_url' => '',
]);
$check(str_contains($unsafe, '2~|~SongName~|~3~|~0'),
    'song titles cannot inject GD wire separators');
$check(str_contains($unsafe, '4~|~Artist~|~5~|~0'),
    'artist names cannot inject GD wire separators');
$check(strlen(ProtocolText::field(str_repeat('A', 200), 128)) === 128,
    'field sanitizer enforces the 128-byte limit');

echo "MUCHOCORE_GD_SONG_INFO_OK\n";
