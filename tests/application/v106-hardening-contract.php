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

$application = (string)file_get_contents(
    __DIR__ . '/../../src/Core/Application.php'
);
$v71Bridge = (string)file_get_contents(
    __DIR__ . '/../../src/V71/DatabaseBridge.php'
);
$v2Bootstrap = (string)file_get_contents(
    __DIR__ . '/../../public/api/v2/bootstrap.php'
);
$readme = (string)file_get_contents(
    __DIR__ . '/../../README.md'
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

// Current score writers use one atomic database upsert instead of
// an application-managed SELECT ... FOR UPDATE transaction. Keep the
// regression contract aligned with the best-score and race-safety rules.
assertV106(
    str_contains($regular, 'INSERT INTO mucho_level_scores') &&
    str_contains($regular, 'ON DUPLICATE KEY UPDATE') &&
    str_contains($regular, 'score_id=LAST_INSERT_ID(score_id)') &&
    str_contains($regular, 'percent=GREATEST(percent,VALUES(percent))'),
    'regular score writes preserve atomic best-progress upsert'
);

assertV106(
    str_contains($platformer, 'INSERT INTO mucho_platformer_scores') &&
    str_contains($platformer, 'ON DUPLICATE KEY UPDATE') &&
    str_contains($platformer, 'score_id=LAST_INSERT_ID(score_id)') &&
    str_contains($platformer, 'VALUES(time_ms)<time_ms') &&
    str_contains($platformer, 'VALUES(points)>points'),
    'platformer score writes preserve atomic best-result upsert'
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


assertV106(
    str_contains($application, '$request->forPluginEvent()') &&
    substr_count($application, 'response->forPluginEvent()') >= 4 &&
    !str_contains($application, "'request' => \$request,") &&
    !str_contains($application, "'error' => \$e,"),
    'plugin lifecycle events use sanitized request/response snapshots'
);

assertV106(
    str_contains($v71Bridge, "new \\MuchoCore\\Database\\Database()") &&
    strpos($v71Bridge, "new \\MuchoCore\\Database\\Database()") <
    strpos($v71Bridge, '$candidates = ['),
    'v7.1 prefers the canonical MuchoCore database connection'
);

assertV106(
    str_contains($v2Bootstrap, "new \\MuchoCore\\Database\\Database()") &&
    strpos($v2Bootstrap, "new \\MuchoCore\\Database\\Database()") <
    strpos($v2Bootstrap, "if (!empty(\$e['DATABASE_URL']))"),
    'API v2 prefers the canonical MuchoCore database connection'
);

assertV106(
    str_contains($readme, '## 📦 MuchoCore v1.0.8') &&
    !str_contains($readme, '**v1.0.6** is the current stable release'),
    'README release state matches v1.0.8'
);

echo "MUCHOCORE_V106_HARDENING_OK\n";
