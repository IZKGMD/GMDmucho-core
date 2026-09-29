<?php

declare(strict_types=1);

/*
 * Static regression contract for the v1.0.9 Gauntlet / Map Pack Maker.
 */

$root = dirname(__DIR__, 2);

$required = [
    'database/migrations/20260929_001_content_packs.php',
    'database/migrations/20260929_002_mappack_unbounded_levels.php',
    'public/admin/actions/contentpacks.php',
    'public/admin/pages/contentpacks.php',
    'public/admin/core/AdminRouter.php',
    'public/admin/config/pages.php',
    'public/admin/index.php',
    'src/Admin/AdminRbac.php',
];

foreach ($required as $relative) {
    if (!is_file($root.'/'.$relative)) {
        fwrite(STDERR, "Missing required file: {$relative}\n");
        exit(1);
    }
}

$migration = file_get_contents($root.'/database/migrations/20260929_001_content_packs.php');
$action = file_get_contents($root.'/public/admin/actions/contentpacks.php');
$page = file_get_contents($root.'/public/admin/pages/contentpacks.php');
$router = file_get_contents($root.'/public/admin/core/AdminRouter.php');
$pages = file_get_contents($root.'/public/admin/config/pages.php');
$admin = file_get_contents($root.'/public/admin/index.php');
$rbac = file_get_contents($root.'/src/Admin/AdminRbac.php');

$contracts = [
    [$migration, 'CREATE TABLE IF NOT EXISTS mucho_gauntlets', 'Gauntlet table'],
    [$migration, 'CREATE TABLE IF NOT EXISTS mucho_map_packs', 'Map Pack table'],
    [$migration, 'ADD COLUMN IF NOT EXISTS name', 'legacy-schema compatibility'],
    [$action, "requirePermission('contentpacks.manage')", 'content-pack permission'],
    [$action, '$expected', 'expected level-count parameter'],
    [$action, 'function contentPackLevelFieldFromPost', 'POST level field parser'],
    [$action, "contentPackLevelFieldFromPost(5)", 'Gauntlet POST level wiring'],
    [$action, "POST['levels']", 'Map Pack POST level wiring'],
    [$action, "'gauntlet-save'", 'Gauntlet save action'],
    [$action, "'gauntlet-delete'", 'Gauntlet delete action'],
    [$action, "'mappack-save'", 'Map Pack save action'],
    [$action, "'mappack-delete'", 'Map Pack delete action'],
    [$action, 'is_deleted', 'deleted-level validation'],
    [$action, 'is_unlisted', 'unlisted-level validation'],
    [$page, 'New Gauntlet', 'Gauntlet creator'],
    [$page, 'New Map Pack', 'Map Pack creator'],
    [$page, 'contentpacks', 'content-pack page'],
    [$router, "'contentpacks'", 'content-pack router'],
    [$pages, "'contentpacks'=>'Gauntlet & Map Pack Maker'", 'page registry'],
    [$admin, 'contentpacks.php', 'content-pack action wiring'],
    [$rbac, "'contentpacks.manage'", 'RBAC permission'],
];

foreach ($contracts as [$content, $needle, $label]) {
    if (!is_string($content) || !str_contains($content, $needle)) {
        fwrite(STDERR, "Contract failed: {$label}\n");
        exit(1);
    }
}

if (
    !str_contains($page, 'renderContentPackLevelInput($db,[],5)') ||
    !str_contains($page, 'name="levels"') ||
    !str_contains($page, 'Any number of unique levels is allowed.')
) {
    fwrite(STDERR, "Contract failed: maker pages do not expose the required Gauntlet/Map Pack level editors\n");
    exit(1);
}

if (
    !str_contains($page, 'name="level_') ||
    !str_contains($page, 'foreach (range(0, $expected - 1) as $index)')
) {
    fwrite(STDERR, "Contract failed: Gauntlet level slot generation is missing\n");
    exit(1);
}

if (substr_count($action, 'contentPackLevelIds(') < 3) {
    fwrite(STDERR, "Contract failed: level list validation is not applied to all makers\n");
    exit(1);
}

if (!str_contains($action, "preg_split('/[\\\\s,]+/'")) {
    fwrite(STDERR, "Contract failed: level list parser does not accept whitespace/comma separators\n");
    exit(1);
}

if (!str_contains($migration, 'MODIFY COLUMN levels TEXT NOT NULL')) {
    fwrite(STDERR, "Contract failed: Map Pack level storage is not unbounded\n");
    exit(1);
}

if (substr_count($action, 'assertContentPackLevels(') < 3) {
    fwrite(STDERR, "Contract failed: selected levels are not checked before persistence\n");
    exit(1);
}

echo "content-pack maker contract: OK\n";
