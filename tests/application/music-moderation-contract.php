<?php

declare(strict_types=1);

function assertMusicModerationContract(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$dashboard = (string)file_get_contents(__DIR__ . '/../../public/dashboard/index.php');
$admin = (string)file_get_contents(__DIR__ . '/../../public/admin/index.php');

assertMusicModerationContract(
    str_contains($dashboard, "function pdMusicModerationRequired()"),
    'dashboard exposes music moderation setting reader'
);

assertMusicModerationContract(
    str_contains($dashboard, 'music-moderation-required.flag'),
    'dashboard reads music moderation flag'
);

assertMusicModerationContract(
    substr_count($dashboard, "'is_verified' => $musicModerationRequired ? 0 : 1") === 2,
    'MP3 and YouTube uploads use moderation setting'
);

assertMusicModerationContract(
    str_contains($admin, 'name="music_moderation_required"'),
    'admin settings expose music moderation checkbox'
);

assertMusicModerationContract(
    str_contains($admin, 'isset($_POST[\'music_moderation_required\'])') &&
    str_contains($admin, "CONTROL_DIR.'/music-moderation-required.flag'"),
    'admin settings persist music moderation flag'
);

echo "MUCHOCORE_MUSIC_MODERATION_CONTRACT_OK\n";
