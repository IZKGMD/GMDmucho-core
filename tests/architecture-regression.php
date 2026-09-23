<?php

declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }

    echo "[PASS] {$message}\n";
}

$root = dirname(__DIR__);

$migration = file_get_contents(
    $root . '/database/migrations/20260923_002_score_persistence.php'
);
check(is_string($migration), 'score persistence migration is readable');
check(
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS mucho_level_scores'),
    'regular score table is part of a clean install'
);
check(
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS mucho_platformer_scores'),
    'platformer score table is part of a clean install'
);
check(
    str_contains($migration, 'UNIQUE KEY uq_mucho_level_score'),
    'regular scores have a logical uniqueness key'
);
check(
    str_contains($migration, 'UNIQUE KEY uq_mucho_platformer_score'),
    'platformer scores have a logical uniqueness key'
);

$regularScore = file_get_contents(
    $root . '/src/Score/LevelScoreController.php'
);
$platformerScore = file_get_contents(
    $root . '/src/Score/PlatformerScoreController.php'
);
check(
    is_string($regularScore) && str_contains($regularScore, 'ON DUPLICATE KEY UPDATE'),
    'regular scores use atomic upsert'
);
check(
    is_string($platformerScore) && str_contains($platformerScore, 'ON DUPLICATE KEY UPDATE'),
    'platformer scores use atomic upsert'
);

$like = file_get_contents($root . '/src/Interaction/LikeRepository.php');
check(
    is_string($like) && str_contains($like, "4 => ['mucho_level_lists', 'list_id']"),
    'level lists support type=4 likes'
);

$levels = file_get_contents($root . '/src/Level/LevelRepository.php');
check(is_string($levels), 'level repository is readable');
check(str_contains($levels, 'case 12:'), 'followed level discovery is implemented');
check(str_contains($levels, 'case 13:'), 'friends level discovery is implemented');
check(str_contains($levels, 'case 27:'), 'sent level discovery is implemented');

$clientIp = file_get_contents($root . '/src/Security/ClientIp.php');
check(
    is_string($clientIp) && str_contains($clientIp, 'MUCHO_TRUSTED_PROXIES'),
    'proxy trust is explicit instead of implicit'
);

echo "[OK] Architecture regression checks passed.\n";
