<?php

declare(strict_types=1);

function assertV106(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }

    echo "PASS {$name}\n";
}

$userRepository = (string)file_get_contents(
    __DIR__ . '/../../src/User/UserRepository.php'
);
$regular = (string)file_get_contents(
    __DIR__ . '/../../src/Score/LevelScoreController.php'
);
$platformer = (string)file_get_contents(
    __DIR__ . '/../../src/Score/PlatformerScoreController.php'
);

assertV106(
    str_contains($userRepository, '(:numeric_query = 1 AND COALESCE(p.user_id, 0) = :user_id)'),
    'numeric player searches are counted by user_id'
);

assertV106(
    substr_count($userRepository, 'a.is_active=1') >= 2 &&
    substr_count($userRepository, 'a.is_banned=0') >= 2,
    'top and creator leaderboards exclude inactive and banned accounts'
);

assertV106(
    str_contains(
        $userRepository,
        "COALESCE(p.creator_points, 0) AS creator_points"
    ) &&
    str_contains(
        $userRepository,
        "COALESCE(p.game_version, 0) > 0 AND COALESCE(p.game_version, 0) < 20"
    ),
    'creator leaderboard keeps pre-2.x compatibility filtering'
);

assertV106(
    str_contains($regular, '$this->db->beginTransaction();') &&
    str_contains($regular, 'FOR UPDATE') &&
    str_contains($regular, '$this->db->commit();') &&
    str_contains($regular, '$this->db->rollBack();'),
    'regular score writes are serialized in a transaction'
);

assertV106(
    str_contains($platformer, '$this->db->beginTransaction();') &&
    str_contains($platformer, 'FOR UPDATE') &&
    str_contains($platformer, '$this->db->commit();') &&
    str_contains($platformer, '$this->db->rollBack();'),
    'platformer score writes are serialized in a transaction'
);

assertV106(
    str_contains(
        $regular,
        'a.account_id=mucho_level_scores.account_id'
    ) &&
    str_contains($regular, 'a.is_active=1') &&
    str_contains($regular, 'a.is_banned=0'),
    'regular score leaderboard excludes inactive and banned accounts'
);

assertV106(
    str_contains($platformer, "'a.is_active=1'") &&
    str_contains($platformer, "'a.is_banned=0'"),
    'platformer score leaderboard excludes inactive and banned accounts'
);

echo "MUCHOCORE_V106_HARDENING_OK\n";
