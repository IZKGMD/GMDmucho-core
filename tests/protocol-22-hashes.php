<?php

declare(strict_types=1);

require __DIR__ . '/../src/Protocol/GdHash.php';
require __DIR__ . '/../src/Protocol/GdSongEncoder.php';

use MuchoCore\Protocol\GdHash;
use MuchoCore\Protocol\GdSongEncoder;

function assertSameValue(mixed $expected, mixed $actual, string $name): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            sprintf(
                "FAIL %s: expected %s, got %s\n",
                $name,
                var_export($expected, true),
                var_export($actual, true)
            )
        );
        exit(1);
    }

    echo "PASS {$name}\n";
}

$short = 'abc';
assertSameValue(
    sha1($short . 'xI25fpAapCQg'),
    GdHash::level($short),
    'short level hash'
);

$data = str_repeat('0123456789', 8);
$sample = '';
$step = intdiv(strlen($data), 40);

for ($i = 0; $i < 40; $i++) {
    $sample .= $data[$i * $step];
}

assertSameValue(
    sha1($sample . 'xI25fpAapCQg'),
    GdHash::level($data),
    'sampled level hash'
);

$metadata = '7,10,1,123,1,0,0,0';

assertSameValue(
    sha1($metadata . 'xI25fpAapCQg'),
    GdHash::metadata($metadata),
    '2.2 metadata hash'
);

$song = (new GdSongEncoder())->encode([
    'id' => 123,
    'name' => 'Test Song',
    'author_id' => 7,
    'author_name' => 'Composer',
    'size' => 4.25,
    'youtube_video_id' => '',
    'download_url' => 'https://cdn.example/song.mp3',
    'youtube_channel_id' => '',
    'is_disabled' => 0,
]);

assertSameValue(
    '1~|~123~|~2~|~Test Song~|~3~|~7~|~4~|~Composer~|~5~|~4.25~|~6~|~~|~10~|~https://cdn.example/song.mp3~|~7~|~~|~8~|~0',
    $song,
    'custom song wire format'
);

echo "MUCHOCORE_22_HASH_AND_SONG_OK\n";
