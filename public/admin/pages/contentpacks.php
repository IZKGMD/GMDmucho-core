<?php

declare(strict_types=1);

/*
 * MuchoCore Admin — Gauntlet & Map Pack Maker.
 *
 * Copyright (C) 2026 IZK
 */

function contentPackLevelsForEditor(
    PDO $db,
    array $ids
): array {
    $ids = array_values(
        array_unique(
            array_filter(
                array_map('intval', $ids),
                static fn(int $id): bool => $id > 0
            )
        )
    );

    if ($ids === []) {
        return [];
    }

    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        'SELECT level_id,name
         FROM levels
         WHERE level_id IN ('.$marks.')'
    );
    $stmt->execute($ids);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[(int)$row['level_id']] = (string)$row['name'];
    }

    return $rows;
}

function renderContentPackLevelInput(
    PDO $db,
    array $ids,
    int $expected
): void {
    $ids = array_values($ids);
    $names = contentPackLevelsForEditor($db, $ids);

    echo '<div style="display:grid;grid-template-columns:repeat('.$expected.',minmax(100px,1fr));gap:8px;margin-top:8px">';

    foreach (range(0, $expected - 1) as $index) {
        $id = (int)($ids[$index] ?? 0);
        $name = $id > 0
            ? ($names[$id] ?? 'Level not found')
            : 'Choose level ID';

        echo '<label style="font-size:12px;color:#8792a7">';
        echo '#'.($index + 1);
        echo '<input type="number" min="1" step="1" name="level_'.($index + 1).'" value="'.h($id > 0 ? $id : '').'" required>';
        echo '<small style="display:block;margin-top:4px;color:#667287;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="'.h($name).'">'.h($name).'</small>';
        echo '</label>';
    }

    echo '</div>';
}



function contentPackDifficultyOptions(): array
{
    return [
        0 => ['Auto', 'https://commons.wikimedia.org/wiki/Special:FilePath/Auto_Icon.svg'],
        1 => ['Easy', 'https://commons.wikimedia.org/wiki/Special:FilePath/Easy_Icon.svg'],
        2 => ['Normal', 'https://commons.wikimedia.org/wiki/Special:FilePath/Normal_Icon.svg'],
        3 => ['Hard', 'https://commons.wikimedia.org/wiki/Special:FilePath/Hard_Icon.svg'],
        4 => ['Harder', 'https://commons.wikimedia.org/wiki/Special:FilePath/Harder_Icon.svg'],
        5 => ['Insane', 'https://commons.wikimedia.org/wiki/Special:FilePath/Insane_Icon.svg'],
        6 => ['Hard Demon', 'https://commons.wikimedia.org/wiki/Special:FilePath/Demon_Icon.webp'],
        7 => ['Easy Demon', 'https://commons.wikimedia.org/wiki/Special:FilePath/Easy_Demon_Icon.webp'],
        8 => ['Medium Demon', 'https://geometrydash.wiki.gg/wiki/Special:Redirect/file/MediumDemon.png'],
        9 => ['Insane Demon', 'https://commons.wikimedia.org/wiki/Special:FilePath/Insane_Demon_Icon.webp'],
        10 => ['Extreme Demon', 'https://commons.wikimedia.org/wiki/Special:FilePath/Extreme_Demon_Icon.webp'],
    ];
}

function contentPackDifficultyIcon(int $value): string
{
    $value = max(0, min(10, $value));
    $options = contentPackDifficultyOptions();

    return $options[$value][1];
}

function renderContentPackDifficultyPicker(int $selected): void
{
    $options = contentPackDifficultyOptions();
    $selected = array_key_exists($selected, $options) ? $selected : 0;
    [$selectedLabel, $selectedIcon] = $options[$selected];

    echo '<details class="mc-field-picker" style="margin-top:8px">';
    echo '<summary style="list-style:none;cursor:pointer;display:flex;align-items:center;gap:10px;min-height:52px;padding:6px 10px;border:1px solid rgba(127,140,163,.32);border-radius:10px;background:rgba(127,140,163,.08)">';
    echo '<img src="'.h($selectedIcon).'" width="42" height="42" alt="">';
    echo '<span style="flex:1"><strong>Give Rate</strong><small class="muted" style="display:block">Selected: '.h($selectedLabel).'</small></span>';
    echo '<span aria-hidden="true" style="font-size:18px">▾</span>';
    echo '</summary>';
    echo '<div style="margin-top:6px;padding:10px;border:1px solid rgba(127,140,163,.34);border-radius:12px;background:var(--card-bg,#111827);box-shadow:0 12px 30px rgba(0,0,0,.35)">';
    echo '<div role="radiogroup" aria-label="Difficulty" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(88px,1fr));gap:7px">';

    foreach ($options as $value => [$label, $icon]) {
        $checked = $selected === $value;
        echo '<label style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;min-height:92px;padding:5px 3px;border:1px solid rgba(127,140,163,.25);border-radius:10px;background:rgba(127,140,163,.08);cursor:pointer;text-align:center">';
        echo '<input type="radio" name="difficulty" value="'.$value.'" '.($checked ? 'checked' : '').' style="position:absolute;opacity:0">';
        echo '<img src="'.h($icon).'" width="66" height="66" alt="">';
        echo '<span style="font-size:10px;font-weight:800;line-height:12px">'.h($label).'</span>';
        echo '</label>';
    }

    echo '</div>';
    echo '<small class="muted" style="display:block;margin-top:8px">Original Geometry Dash difficulty artwork is loaded from wiki-hosted files. Click an icon to select the difficulty ID automatically.</small>';
    echo '</div>';
    echo '</details>';
}


function renderContentPackColorPicker(string $field, int $selected): void
{
    $presets = contentPackColorPresets();
    $selected = array_key_exists($selected, $presets) ? $selected : 0;

    echo '<details class="mc-field-picker" style="margin-top:8px">';
    echo '<summary style="list-style:none;cursor:pointer;display:flex;align-items:center;gap:10px;min-height:52px;padding:6px 10px;border:1px solid rgba(127,140,163,.32);border-radius:10px;background:rgba(127,140,163,.08)">';
    echo '<span style="width:36px;height:36px;border-radius:9px;border:2px solid rgba(255,255,255,.3);background:'.$presets[$selected].';display:block"></span>';
    echo '<span style="flex:1"><strong>'.h(ucwords(str_replace('_', ' ', $field))).'</strong><small class="muted" style="display:block">Selected: Color '.h($selected).'</small></span>';
    echo '<span aria-hidden="true" style="font-size:18px">▾</span>';
    echo '</summary>';
    echo '<div style="margin-top:6px;padding:10px;border:1px solid rgba(127,140,163,.34);border-radius:12px;background:var(--card-bg,#111827);box-shadow:0 12px 30px rgba(0,0,0,.35)">';
    echo '<div role="radiogroup" aria-label="'.h($field).' color" style="display:flex;flex-wrap:wrap;gap:7px">';

    foreach ($presets as $value => $hex) {
        $checked = $selected === $value;
        echo '<label style="position:relative;width:48px;height:48px;display:block;cursor:pointer">';
        echo '<input type="radio" name="'.h($field).'" value="'.$value.'" '.($checked ? 'checked' : '').' style="position:absolute;opacity:0;inset:0;cursor:pointer">';
        echo '<span style="display:flex;width:48px;height:48px;align-items:center;justify-content:center;border-radius:12px;background:'.$hex.';border:2px solid rgba(255,255,255,.32);font-size:10px;font-weight:800;color:'.($value === 0 ? '#1f2937' : '#fff').';text-shadow:0 1px 2px rgba(0,0,0,.3)">Color '.$value.'</span>';
        echo '</label>';
    }

    echo '</div>';
    echo '<small class="muted" style="display:block;margin-top:8px">Click a color. The numeric Color ID is submitted automatically.</small>';
    echo '</div>';
    echo '</details>';
}


$gauntlets = $db->query(
    'SELECT id,name,level1,level2,level3,level4,level5,enabled,sort_order
     FROM mucho_gauntlets
     ORDER BY sort_order ASC,id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$mapPacks = $db->query(
    'SELECT id,name,levels,stars,coins,difficulty,color1,color2,enabled,sort_order
     FROM mucho_map_packs
     ORDER BY sort_order ASC,id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

echo '<div class="section-actions" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">';
echo '<span class="muted">Build client-visible Gauntlets and Map Packs. Only enabled entries with valid levels are published.</span>';
echo '</div>';

echo '<div class="grid" style="align-items:start">';

echo '<section class="card">';
echo '<h2 style="margin-top:0">New Gauntlet</h2>';
echo '<form method="post">';
echo '<input type="hidden" name="csrf" value="'.csrf().'">';
echo '<input type="hidden" name="action" value="gauntlet-save">';
echo '<input type="hidden" name="return" value="contentpacks">';
echo '<input name="name" maxlength="96" placeholder="Gauntlet name" required>';
echo '<div style="display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:8px">';
echo '<label>Order<input type="number" name="sort_order" value="0"></label>';
echo '<label style="display:flex;gap:6px;align-items:end">Enabled <input type="checkbox" name="enabled" checked></label>';
echo '</div>';
renderContentPackLevelInput($db,[],5);
echo '<button style="margin-top:10px">Create Gauntlet</button>';
echo '</form>';
echo '</section>';

echo '<section class="card">';
echo '<h2 style="margin-top:0">New Map Pack</h2>';
echo '<form method="post">';
echo '<input type="hidden" name="csrf" value="'.csrf().'">';
echo '<input type="hidden" name="action" value="mappack-save">';
echo '<input type="hidden" name="return" value="contentpacks">';
echo '<input name="name" maxlength="64" placeholder="Map Pack name" required>';
echo '<div class="grid" style="margin-top:8px">';
echo '<label>Stars<input type="number" name="stars" min="0" max="255" value="10"></label>';
echo '<label>Coins<input type="number" name="coins" min="0" max="255" value="3"></label>';
renderContentPackDifficultyPicker(1);
renderContentPackColorPicker('color1', 0);
renderContentPackColorPicker('color2', 3);
echo '<label>Order<input type="number" name="sort_order" value="0"></label>';
echo '</div>';
echo '<label style="display:flex;gap:6px;align-items:center;margin-top:8px">Enabled <input type="checkbox" name="enabled" checked></label>';
echo '<label style="display:block;margin-top:10px">Levels';
echo '<textarea name="levels" rows="5" placeholder="Level IDs separated by spaces, commas, or new lines" required></textarea>';
echo '</label>';
echo '<small class="muted">Any number of unique levels is allowed.</small>';
echo '<button style="margin-top:10px">Create Map Pack</button>';
echo '</form>';
echo '</section>';

echo '</div>';

echo '<h2 style="margin:24px 0 10px">Gauntlets</h2>';

if ($gauntlets === []) {
    echo '<div class="card muted">No gauntlets created yet.</div>';
} else {
    foreach ($gauntlets as $g) {
        echo '<section class="card" style="margin-bottom:10px">';
        echo '<form method="post">';
        echo '<input type="hidden" name="csrf" value="'.csrf().'">';
        echo '<input type="hidden" name="action" value="gauntlet-save">';
        echo '<input type="hidden" name="id" value="'.h($g['id']).'">';
        echo '<div style="display:grid;grid-template-columns:minmax(180px,2fr) minmax(90px,1fr) auto;gap:8px;align-items:end">';
        echo '<label>Name<input name="name" maxlength="96" value="'.h($g['name']).'" required></label>';
        echo '<label>Order<input type="number" name="sort_order" value="'.h($g['sort_order']).'"></label>';
        echo '<label style="display:flex;gap:6px;align-items:center">Enabled <input type="checkbox" name="enabled" '.((int)$g['enabled'] ? 'checked' : '').'></label>';
        echo '</div>';
        renderContentPackLevelInput($db,[$g['level1'],$g['level2'],$g['level3'],$g['level4'],$g['level5']],5);
        echo '<div style="display:flex;gap:8px;margin-top:10px">';
        echo '<button>Save</button>';
        echo '</div>';
        echo '</form>';
        echo '<form method="post" style="margin-top:6px" onsubmit="return confirm(\'Delete this Gauntlet?\')">';
        echo '<input type="hidden" name="csrf" value="'.csrf().'">';
        echo '<input type="hidden" name="action" value="gauntlet-delete">';
        echo '<input type="hidden" name="id" value="'.h($g['id']).'">';
        echo '<button class="red">Delete</button>';
        echo '</form>';
        echo '</section>';
    }
}

echo '<h2 style="margin:24px 0 10px">Map Packs</h2>';

if ($mapPacks === []) {
    echo '<div class="card muted">No Map Packs created yet.</div>';
} else {
    foreach ($mapPacks as $m) {
        $levelIds = preg_split('/,/', (string)$m['levels']) ?: [];
        $levelIds = array_map('intval', $levelIds);

        echo '<section class="card" style="margin-bottom:10px">';
        echo '<form method="post">';
        echo '<input type="hidden" name="csrf" value="'.csrf().'">';
        echo '<input type="hidden" name="action" value="mappack-save">';
        echo '<input type="hidden" name="id" value="'.h($m['id']).'">';
        echo '<div style="display:grid;grid-template-columns:minmax(180px,2fr) repeat(3,minmax(90px,1fr));gap:8px;align-items:end">';
        echo '<label>Name<input name="name" maxlength="64" value="'.h($m['name']).'" required></label>';
        echo '<label>Stars<input type="number" name="stars" min="0" max="255" value="'.h($m['stars']).'"></label>';
        echo '<label>Coins<input type="number" name="coins" min="0" max="255" value="'.h($m['coins']).'"></label>';
        renderContentPackDifficultyPicker((int)$m['difficulty']);
        echo '</div>';
        echo '<div class="grid" style="margin-top:8px">';
        renderContentPackColorPicker('color1', (int)$m['color1']);
        renderContentPackColorPicker('color2', (int)$m['color2']);
        echo '<label>Order<input type="number" name="sort_order" value="'.h($m['sort_order']).'"></label>';
        echo '<label style="display:flex;gap:6px;align-items:center">Enabled <input type="checkbox" name="enabled" '.((int)$m['enabled'] ? 'checked' : '').'></label>';
        echo '</div>';
        echo '<label style="display:block;margin-top:10px">Levels';
        echo '<textarea name="levels" rows="5" required>'.h(implode(' ', $levelIds)).'</textarea>';
        echo '</label>';
        echo '<small class="muted">Any number of unique levels is allowed.</small>';
        echo '<div style="display:flex;gap:8px;margin-top:10px"><button>Save</button></div>';
        echo '</form>';
        echo '<form method="post" style="margin-top:6px" onsubmit="return confirm(\'Delete this Map Pack?\')">';
        echo '<input type="hidden" name="csrf" value="'.csrf().'">';
        echo '<input type="hidden" name="action" value="mappack-delete">';
        echo '<input type="hidden" name="id" value="'.h($m['id']).'">';
        echo '<button class="red">Delete</button>';
        echo '</form>';
        echo '</section>';
    }
}

echo '<p class="muted" style="margin-top:16px">Gauntlets require exactly 5 unique levels. Map Packs accept any number of unique levels. Difficulty and colors are selected visually; the server stores the original numeric GD values.</p>';
