<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$file = $root . '/public/admin/pages/levels-moderation.php';

if (!is_file($file)) {
    throw new RuntimeException('Levels admin page is missing.');
}

$text = (string)file_get_contents($file);

$needles = [
    "game_version",
    "min_stars",
    "featured",
    "deleted",
    "downloads DESC, level_id DESC",
    "likes DESC, level_id DESC",
    "stars DESC, level_id DESC",
    "EXISTS (",
    "search_creator.username LIKE :creator",
];

foreach ($needles as $needle) {
    if (!str_contains($text, $needle)) {
        throw new RuntimeException('Level search filter is missing: ' . $needle);
    }
}

if (!str_contains($text, "if ($page==='levels')")) {
    throw new RuntimeException('Level search must remain page-scoped.');
}

echo "level-search-admin-contract: OK\n";
