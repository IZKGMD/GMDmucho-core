<?php

declare(strict_types=1);

$checks = [
    [
        __DIR__ . '/../../src/Level/LevelRepository.php',
        "case 6:\n            case 17:",
        '2.1 featured level type coverage',
    ],
    [
        __DIR__ . '/../../src/Level/LevelRepository.php',
        '$gameVersion > 21',
        '2.1 featured excludes Epic until 2.2',
    ],
    [
        __DIR__ . '/../../src/Protocol/GdLevelListEncoder.php',
        'Preserve custom star values',
        '2.1 list hash preserves custom stars',
    ],
    [
        __DIR__ . '/../../src/Protocol/GdLegacyText.php',
        'if ($gameVersion < 20)',
        'GD 1.9 description storage branch',
    ],
    [
        __DIR__ . '/../../src/Protocol/GdLegacyText.php',
        'decodeDescriptionForStorage',
        'GD 1.9 description update decoder',
    ],
    [
        __DIR__ . '/../../src/Protocol/GdLevelDownloadEncoder.php',
        'if ($gameVersion < 20)',
        'GD 1.9 download description branch',
    ],
    [
        __DIR__ . '/../../src/Level/LevelRepository.php',
        'Legacy GD 1.9',
        'GD 1.9 special difficulty filters',
    ],
    [
        __DIR__ . '/../../src/Protocol/GdLevelListEncoder.php',
        "int)\$level['coins']",
        'multi-level hash uses star coins',
    ],
    [
        __DIR__ . '/../../src/Protocol/GdLevelDownloadEncoder.php',
        'protocolStars = max',
        'download preserves custom star values',
    ],
    [
        __DIR__ . '/../../src/Level/LevelTransferService.php',
        "stars'] ?? 0) > 0",
        'rated level deletion guard',
    ],
    [
        __DIR__ . '/../../src/Level/LevelRepository.php',
        "completedLevels",
        'GD 1.9 completion-level filters',
    ],
    [
        __DIR__ . '/../../src/Level/LevelRepository.php',
        "customSong",
        'GD 1.9 song filters',
    ],
    [
        __DIR__ . '/../../src/Protocol/GdCommentEncoder.php',
        'if ($badge > 0)',
        '2.1 comment badge color is conditional',
    ],
    [
        __DIR__ . '/../../src/Protocol/GdLegacyText.php',
        'encodeDescriptionForResponse',
        '2.1 level description Base64 response encoding',
    ],
    [
        __DIR__ . '/../../src/Protocol/GdLegacyText.php',
        'decodeWireText',
        '2.1 incoming text Base64 decoding',
    ],
    [
        __DIR__ . '/../../src/Protocol/GdCommentEncoder.php',
        'GdLegacyText::encodeCommentForResponse',
        'legacy comment response encoding guard',
    ],
    [
        __DIR__ . '/../../src/Protocol/GdLegacyText.php',
        'GD 2.0+ expects level comments as plain protocol text',
        'GD 2.0+ comments stay plain text',
    ],
    [
        __DIR__ . '/../../src/Protocol/GdLevelListEncoder.php',
        'GdLegacyText::encodeDescriptionForResponse',
        '2.1 list description Base64 response encoding',
    ],
    [
        __DIR__ . '/../../src/User/UserService.php',
        'ClientVersion::fromValues',
        '2.1 profile update uses protocol family detection',
    ],
    [
        __DIR__ . '/../../src/User/UserService.php',
        '$effectiveGameVersion >= 20 && $effectiveGameVersion <= 21',
        'GD 2.0/2.1 profile state normalization gate',
    ],
    [
        __DIR__ . '/../../src/User/UserService.php',
        'normalizeDemonInfo',
        '2.1 demon progress normalization',
    ],
    [
        __DIR__ . '/../../src/User/UserService.php',
        'normalizeStarInfo',
        '2.1 star/platformer progress normalization',
    ],
    [
        __DIR__ . '/../../src/User/UserService.php',
        'numericIdList((string)$raw, 1000)',
        '2.1 demon info level id bound',
    ],
    [
        __DIR__ . '/../../src/Level/LevelController.php',
        'if ($type === 13)',
        '2.1 friends level authentication gate',
    ],
    [
        __DIR__ . '/../../src/Level/LevelController.php',
        '$this->auth->authenticate($accountId, $credential)',
        '2.1 friends level GJP verification',
    ],
    [
        __DIR__ . '/../../database/migrations/017_level_unlisted2.php',
        'ADD COLUMN IF NOT EXISTS unlisted2',
        'unlisted2 migration',
    ],
    [
        __DIR__ . '/../../src/Level/LevelTransferRepository.php',
        "'unlisted2'",
        'unlisted2 repository field',
    ],
    [
        __DIR__ . '/../../src/Level/LevelTransferService.php',
        'if ((int)($level[\'unlisted2\'] ?? 0) !== 0)',
        'restricted download guard',
    ],
    [
        __DIR__ . '/../../src/Level/LevelTransferService.php',
        'friend_account_id=:owner',
        'owner/friend authorization',
    ],
    [
        __DIR__ . '/../../src/Level/LevelTransferService.php',
        'GdLegacyText::encodeDescriptionForStorage',
        'legacy description storage',
    ],
    [
        __DIR__ . '/../../src/Level/LevelRepository.php',
        'case 27:',
        'suggested level discovery type 27',
    ],
    [
        __DIR__ . '/../../database/migrations/018_profile_protocol_state.php',
        "demon_info",
        '2.2 profile state migration',
    ],
    [
        __DIR__ . '/../../src/User/UserService.php',
        "'demon_info' =>",
        '2.2 demon info persistence',
    ],
    [
        __DIR__ . '/../../src/User/UserService.php',
        "'star_info' =>",
        '2.2 star info persistence',
    ],
    [
        __DIR__ . '/../../src/User/UserService.php',
        "'platformer_info' =>",
        '2.2 platformer info persistence',
    ],
    [
        __DIR__ . '/../../src/User/UserService.php',
        "'glow' =>",
        '2.2 glow persistence',
    ],
    [
        __DIR__ . '/../../src/User/UserService.php',
        "'special' =>",
        '2.2 special persistence',
    ],
    [
        __DIR__ . '/../../src/User/UserController.php',
        '$gjp=$request->gdCredential();',
        '2.2 version-aware score credentials',
    ],
    [
        __DIR__ . '/../../src/User/UserController.php',
        '$request->clientVersion()->effectiveGameVersion(),',
        'leaderboard uses client protocol version',
    ],
    [
        __DIR__ . '/../../src/User/UserService.php',
        '$this->auth->authenticate($accountId, $credential);',
        'leaderboard authenticates supplied account context',
    ],
    [
        __DIR__ . '/../../src/User/UserRepository.php',
        "COALESCE(p.game_version, 0) > 0 AND COALESCE(p.game_version, 0) < 20",
        '1.9 leaderboard keeps legacy-generation users',
    ],
    [
        __DIR__ . '/../../src/User/UserService.php',
        'updateGJUserScore returns the legacy userID',
        'score update returns user ID',
    ],
    [
        __DIR__ . '/../../src/Interaction/CommentService.php',
        'public function getUserComments(',
        'legacy user comment history surface',
    ],
    [
        __DIR__ . '/../../src/Protocol/GdMessageEncoder.php',
        "1 - (int)(\$message['is_read'] ?? 0)",
        'message isNew inversion',
    ],

    [
        __DIR__ . '/../../src/Moderation/ModerationController.php',
        'return $request->gdCredential();',
        '2.2 version-aware moderation credentials',
    ],
    [
        __DIR__ . '/../../src/Score/PlatformerScoreController.php',
        "':15:'.(int)\$row['color3'].",
        '2.2 platformer color3 field',
    ],
    [
        __DIR__ . '/../../src/Score/PlatformerScoreController.php',
        "':42:'.date(",
        '2.2 platformer timestamp field',
    ],
    [
        __DIR__ . '/../../src/LevelList/LevelListRepository.php',
        'COALESCE(p.user_id, l.account_id) AS user_id',
        '2.2 level list canonical user id',
    ],
    [
        __DIR__ . '/../../src/LevelList/LevelListService.php',
        "array_diff(",
        '2.2 friend list excludes self',
    ],
    [
        __DIR__ . '/../../src/LevelList/LevelListService.php',
        '$filters[\'difficulties\']',
        '2.2 level list multi-difficulty filters',
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
