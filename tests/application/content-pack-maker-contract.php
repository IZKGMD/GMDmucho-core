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
$migration2 = file_get_contents($root.'/database/migrations/20260929_002_mappack_unbounded_levels.php');
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
    [$page, 'Give Rate', 'visual difficulty picker'],
    [$page, 'contentPackDifficultyOptions', 'difficulty options'],
    [$page, 'contentPackDifficultyIcon', 'difficulty icon resolver'],
    [$page, 'Special:FilePath/Auto_Icon.svg', 'original Auto difficulty artwork'],
    [$page, 'Special:FilePath/Easy_Icon.svg', 'original Easy difficulty artwork'],
    [$page, 'Special:FilePath/Normal_Icon.svg', 'original Normal difficulty artwork'],
    [$page, 'Special:FilePath/Hard_Icon.svg', 'original Hard difficulty artwork'],
    [$page, 'Special:FilePath/Harder_Icon.svg', 'original Harder difficulty artwork'],
    [$page, 'Special:FilePath/Insane_Icon.svg', 'original Insane difficulty artwork'],
    [$page, 'Special:FilePath/Demon_Icon.webp', 'original Hard Demon artwork'],
    [$page, 'Special:FilePath/Easy_Demon_Icon.webp', 'original Easy Demon artwork'],
    [$page, 'Special:Redirect/file/MediumDemon.png', 'original Medium Demon artwork'],
    [$page, 'Special:FilePath/Insane_Demon_Icon.webp', 'original Insane Demon artwork'],
    [$page, 'Special:FilePath/Extreme_Demon_Icon.webp', 'original Extreme Demon artwork'],
    [$page, '<details class="mc-field-picker"', 'native clickable selector'],
    [$page, 'type="radio"', 'native selector inputs'],
    [$page, 'name="difficulty"', 'difficulty form field'],
    [$page, "renderContentPackColorPicker('color1'", 'Color 1 form value'],
    [$page, "renderContentPackColorPicker('color2'", 'Color 2 form value'],
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
    str_contains($page, 'name="difficulty" min=') ||
    str_contains($page, 'name="color1" min=') ||
    str_contains($page, 'name="color2" min=')
) {
    fwrite(STDERR, "Contract failed: difficulty/color must not be exposed as numeric inputs\n");
    exit(1);
}

if (
    substr_count($page, '<details class="mc-dropdown"') < 3 ||
    substr_count($page, 'type="radio"') < 8 ||
    !str_contains($page, 'data-difficulty-preview') ||
    !str_contains($page, 'data-color-preview') ||
    !str_contains($page, 'onchange="const d=this.closest(\\'details\\')')
) {
    fwrite(STDERR, "Contract failed: native dropdown selectors are incomplete\n");
    exit(1);
}

foreach ([
    'Special:FilePath/Auto_Icon.svg',
    'Special:FilePath/Easy_Icon.svg',
    'Special:FilePath/Normal_Icon.svg',
    'Special:FilePath/Hard_Icon.svg',
    'Special:FilePath/Harder_Icon.svg',
    'Special:FilePath/Insane_Icon.svg',
    'Special:FilePath/Demon_Icon.webp',
    'Special:FilePath/Easy_Demon_Icon.webp',
    'Special:Redirect/file/MediumDemon.png',
    'Special:FilePath/Insane_Demon_Icon.webp',
    'Special:FilePath/Extreme_Demon_Icon.webp',
] as $needle) {
    if (!str_contains($page, $needle)) {
        fwrite(STDERR, "Contract failed: original wiki difficulty artwork missing: {$needle}\n");
        exit(1);
    }
}

foreach ([
    "6 => ['Hard Demon'",
    "7 => ['Easy Demon'",
    "8 => ['Medium Demon'",
    "9 => ['Insane Demon'",
    "10 => ['Extreme Demon'",
] as $needle) {
    if (!str_contains($page, $needle)) {
        fwrite(STDERR, "Contract failed: demon difficulty ID mapping missing: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($page, "Special:Redirect/file/Colour000.png") ||
    !str_contains($page, "for ($value = 0; $value <= 106; $value++)")) {
    fwrite(STDERR, "Contract failed: official icon color palette selector is missing\n");
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

if (!str_contains((string)$migration2, 'MODIFY COLUMN levels TEXT NOT NULL')) {
    fwrite(STDERR, "Contract failed: Map Pack level storage is not unbounded\n");
    exit(1);
}

if (substr_count($action, 'assertContentPackLevels(') < 3) {
    fwrite(STDERR, "Contract failed: selected levels are not checked before persistence\n");
    exit(1);
}

echo "content-pack maker contract: OK\n";
