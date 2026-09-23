<?php

declare(strict_types=1);

$checks = [
    [
        __DIR__ . '/../database/migrations/017_level_unlisted2.php',
        'ADD COLUMN IF NOT EXISTS unlisted2',
        'unlisted2 migration',
    ],
    [
        __DIR__ . '/../src/Level/LevelTransferRepository.php',
        "'unlisted2'",
        'unlisted2 repository field',
    ],
    [
        __DIR__ . '/../src/Level/LevelTransferService.php',
        "if ((int)($level['unlisted2'] ?? 0) !== 0)",
        'restricted download guard',
    ],
    [
        __DIR__ . '/../src/Level/LevelTransferService.php',
        'friend_account_id=:owner',
        'owner/friend authorization',
    ],
    [
        __DIR__ . '/../src/Level/LevelTransferService.php',
        'GdLegacyText::encodeDescriptionForStorage',
        'legacy description storage',
    ],
    [
        __DIR__ . '/../src/Level/LevelRepository.php',
        'case 27:',
        'suggested level discovery type 27',
    ],
    [
        __DIR__ . '/../database/migrations/018_profile_protocol_state.php',
        "demon_info",
        '2.2 profile state migration',
    ],
    [
        __DIR__ . '/../src/User/UserService.php',
        "'demon_info' =>",
        '2.2 demon info persistence',
    ],
    [
        __DIR__ . '/../src/User/UserService.php',
        "'star_info' =>",
        '2.2 star info persistence',
    ],
    [
        __DIR__ . '/../src/User/UserService.php',
        "'platformer_info' =>",
        '2.2 platformer info persistence',
    ],
    [
        __DIR__ . '/../src/User/UserService.php',
        "'glow' =>",
        '2.2 glow persistence',
    ],
    [
        __DIR__ . '/../src/User/UserService.php',
        "'special' =>",
        '2.2 special persistence',
    ],
    [
        __DIR__ . '/../src/User/UserController.php',
        '$gjp=$request->gdCredential();',
        '2.2 version-aware score credentials',
    ],
    [
        __DIR__ . '/../src/Moderation/ModerationController.php',
        'return $request->gdCredential();',
        '2.2 version-aware moderation credentials',
    ],
    [
        __DIR__ . '/../src/Score/PlatformerScoreController.php',
        "':15:'.(int)$row['color3'].",
        '2.2 platformer color3 field',
    ],
];

foreach ($checks as [$file, $needle, $name]) {
    $contents = file_get_contents($file);

    if ($contents === false || !str_contains($contents, $needle)) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

echo "MUCHOCORE_LEVEL_PROTOCOL_GUARDS_OK\n";
