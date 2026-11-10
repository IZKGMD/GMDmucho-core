<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Protocol\GdSongEncoder;
use MuchoCore\Protocol\GdLevelListEncoder;

$check = static function (bool $condition, string $description): void {
    if (!$condition) {
        throw new RuntimeException('Cross-version music: ' . $description);
    }
    echo 'PASS ' . $description . PHP_EOL;
};

$rawUrl = 'https://music.example.test/songs/track.mp3?download=1&v=2';
$encodedUrl = rawurlencode($rawUrl);
$song = [
    'id' => 99999002,
    'name' => 'MuchoCore Music Test',
    'author_id' => 30,
    'author_name' => 'Mucho Music',
    'size' => 0.5,
    'download_url' => $rawUrl,
    'youtube_video_id' => '',
    'youtube_channel_id' => '',
    'is_disabled' => 0,
];

$encoder = new GdSongEncoder();
$wire = $encoder->encode($song);
$check(str_contains($wire, '10~|~' . $encodedUrl . '~|~7~|~'),
    'GD metadata contains URL-encoded MP3 location');
$check(str_ends_with($wire, '8~|~0'),
    'enabled song uses legacy compatible disabled=0');
$check(rawurldecode(GdSongEncoder::encodeDownloadUrl($rawUrl)) === $rawUrl,
    'encoded URL decodes to exact original download link');
$check(GdSongEncoder::encodeDownloadUrl($encodedUrl) === $encodedUrl,
    'already percent-encoded link is never double-encoded');
$escapedPathUrl = 'https://music.example.test/song%20title.mp3';
$check(GdSongEncoder::encodeDownloadUrl($escapedPathUrl) === rawurlencode($escapedPathUrl),
    'an escape inside a raw URL never bypasses full URL encoding');
foreach (['javascript:alert(1)', 'file:///etc/passwd', "https://host.test/\nX:",
          'http://user:pass@host.test/music.mp3', ''] as $bad) {
    $check(GdSongEncoder::encodeDownloadUrl($bad) === '',
        'invalid music URL is excluded from client response');
}

$level = [
    'level_id' => 12345,
    'account_id' => 23,
    'user_id' => 23,
    'username' => 'MusicTester',
    'name' => 'Music Level',
    'level_version' => 1,
    'downloads' => 0,
    'audio_track' => 0,
    'game_version' => 19,
    'likes' => 0,
    'demon' => 0,
    'demon_difficulty' => 0,
    'auto_level' => 0,
    'stars' => 0,
    'featured' => 0,
    'epic' => 0,
    'object_count' => 10,
    'description' => '',
    'length' => 1,
    'original_level_id' => 0,
    'two_player' => 0,
    'coins' => 0,
    'coins_verified' => 0,
    'requested_stars' => 0,
    'ldm' => 0,
    'song_id' => $song['id'],
    'song_protocol_id' => $song['id'],
    'song_name' => $song['name'],
    'song_author_id' => $song['author_id'],
    'song_author_name' => $song['author_name'],
    'song_size' => $song['size'],
    'song_download_url' => $song['download_url'],
    'song_youtube_video_id' => '',
    'song_youtube_channel_id' => '',
    'created_at' => '2026-11-10 14:00:00',
    'updated_at' => '2026-11-10 14:00:00',
];
$levelEncoder = new GdLevelListEncoder();
foreach ([19, 20, 21, 22] as $gameVersion) {
    $encoded = $levelEncoder->encode(
        [$level], 1, 0, 10, $gameVersion, $gameVersion === 22 ? 51 : 0
    );
    $parts = explode('#', $encoded);
    $check(count($parts) === 5 && $parts[2] === $wire,
        "GD {$gameVersion} level lists reuse exactly the same playable song metadata");
}
$pre19 = $levelEncoder->encode([$level], 1, 0, 10, 18, 0);
$check((explode('#', $pre19))[2] === '',
    'GD before 1.9 is not falsely advertised as natively supporting custom music');

echo "MUCHOCORE_SONG_VERSION_MATRIX_OK\n";
