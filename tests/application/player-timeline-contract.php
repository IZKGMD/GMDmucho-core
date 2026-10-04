<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function assertTimeline(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$files = [
    'public/admin/pages/player-timeline.php',
    'public/admin/pages/players.php',
    'public/admin/core/AdminRouter.php',
    'public/admin/config/pages.php',
    'src/Admin/AdminRbac.php',
];

foreach ($files as $relative) {
    assertTimeline(is_file($root . '/' . $relative), 'Missing Player Timeline file: ' . $relative);
}

$page = (string)file_get_contents($root . '/public/admin/pages/player-timeline.php');
$players = (string)file_get_contents($root . '/public/admin/pages/players.php');
$router = (string)file_get_contents($root . '/public/admin/core/AdminRouter.php');
$pages = (string)file_get_contents($root . '/public/admin/config/pages.php');
$rbac = (string)file_get_contents($root . '/src/Admin/AdminRbac.php');

assertTimeline(str_contains($page, "requirePermission('players.view')"), 'Timeline must require players.view.');
assertTimeline(str_contains($page, 'Activity Timeline'), 'Timeline UI is missing.');
assertTimeline(str_contains($page, 'mucho_level_scores'), 'Timeline must include player score activity.');
assertTimeline(str_contains($page, 'mucho_security_events'), 'Timeline must include security activity.');
assertTimeline(str_contains($page, 'admin_audit_logs'), 'Timeline must include operator activity.');
assertTimeline(str_contains($players, 'page=playertimeline'), 'Players page does not link to the timeline.');
assertTimeline(str_contains($router, "'playertimeline'"), 'Admin router does not register Player Timeline.');
assertTimeline(str_contains($pages, "'playertimeline'=>'Player Timeline'"), 'Page registry does not expose Player Timeline.');
assertTimeline(str_contains($rbac, "'playertimeline' => 'players.view'"), 'Player Timeline RBAC mapping is missing.');

echo "player-timeline-contract: OK\n";
