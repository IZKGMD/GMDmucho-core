<?php

declare(strict_types=1);

$files = [
    __DIR__ . '/../public/admin/index.php',
    __DIR__ . '/../public/api/v2/music-upload.php',
    __DIR__ . '/../docker/Dockerfile',
];

foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FAIL missing {$file}\n");
        exit(1);
    }
}

$admin = file_get_contents($files[0]);
$api = file_get_contents($files[1]);
$docker = file_get_contents($files[2]);

$checks = [
    [$admin, 'name="music_file"', 'admin music upload field'],
    [$admin, "enctype=\"multipart/form-data\"", 'admin multipart form'],
    [$admin, "action\" value=\"music-upload", 'admin music action'],
    [$api, "mkdir(MUSIC_DIR", 'api creates music storage'],
    [$api, "MUSIC_MAX = 20 * 1024 * 1024", 'api music size limit'],
    [$docker, 'upload_max_filesize=20M', 'php upload_max_filesize'],
    [$docker, 'post_max_size=22M', 'php post_max_size'],
];

foreach ($checks as [$haystack, $needle, $name]) {
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

echo "MUCHOCORE_MUSIC_CONTRACT_OK\n";
