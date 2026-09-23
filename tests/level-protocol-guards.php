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
];

foreach ($checks as [$file, $needle, $name]) {
    $contents = file_get_contents($file);

    if ($contents === false || !str_contains($contents, $needle)) {
        fwrite(STDERR, "FAIL {$name}
");
        exit(1);
    }

    echo "PASS {$name}
";
}

echo "MUCHOCORE_LEVEL_PROTOCOL_GUARDS_OK
";
