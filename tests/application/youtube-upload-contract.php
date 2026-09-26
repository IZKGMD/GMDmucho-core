<?php

declare(strict_types=1);

function assertYouTubeContract(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$dashboard = (string)file_get_contents(__DIR__ . '/../../public/dashboard/index.php');

foreach ([
    'data-music-tab="file"',
    'data-music-tab="youtube"',
    'data-music-panel="youtube"',
    'name="youtube_url"',
    'name="youtube_title"',
    'name="youtube_artist"',
    'value="upload_youtube"',
    "MUCHO_YTDLP_BIN",
    "MUCHO_ENABLE_YOUTUBE_IMPORT",
    "--remote-components ejs:github",
    "denoland/deno/releases/latest/download/deno-x86_64-unknown-linux-gnu.zip",
] as $needle) {
    assertYouTubeContract(
        str_contains($dashboard, $needle),
        'dashboard contains ' . $needle
    );
}

assertYouTubeContract(
    str_contains($dashboard, "if ($action === 'upload_youtube')"),
    'dashboard registers YouTube upload action'
);

assertYouTubeContract(
    str_contains($dashboard, "--audio-format mp3") &&
    str_contains($dashboard, "--no-playlist") &&
    str_contains($dashboard, "--max-filesize 64M"),
    'YouTube importer uses bounded audio conversion'
);

assertYouTubeContract(
    str_contains($dashboard, "youtube.com") &&
    str_contains($dashboard, "youtu.be"),
    'YouTube importer restricts URL hosts'
);

echo "MUCHOCORE_YOUTUBE_UPLOAD_CONTRACT_OK\n";
