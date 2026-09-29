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
        0 => ['Auto', 'https://upload.wikimedia.org/wikipedia/commons/a/a8/Auto_Icon.svg'],
        1 => ['Easy', 'https://upload.wikimedia.org/wikipedia/commons/c/ce/Easy_Icon.svg'],
        2 => ['Normal', 'https://upload.wikimedia.org/wikipedia/commons/4/48/Normal_Icon.svg'],
        3 => ['Hard', 'https://upload.wikimedia.org/wikipedia/commons/2/24/Hard_Icon.svg'],
        4 => ['Harder', 'https://upload.wikimedia.org/wikipedia/commons/3/34/Harder_Icon.svg'],
        5 => ['Insane', 'https://upload.wikimedia.org/wikipedia/commons/6/6c/Insane_Icon.svg'],
        6 => ['Hard Demon', 'https://upload.wikimedia.org/wikipedia/commons/1/1c/Demon_Icon.webp'],
        7 => ['Easy Demon', 'https://upload.wikimedia.org/wikipedia/commons/a/a5/Easy_Demon_Icon.webp'],
        8 => ['Medium Demon', 'https://static.wikia.nocookie.net/geometry-dash/images/e/e2/MediumDemon.png/revision/latest/scale-to-width-down/512?cb=20250312081829'],
        9 => ['Insane Demon', 'https://upload.wikimedia.org/wikipedia/commons/a/ae/Insane_Demon_Icon.webp'],
        10 => ['Extreme Demon', 'https://upload.wikimedia.org/wikipedia/commons/3/33/Extreme_Demon_Icon.webp'],
    ];
}

function contentPackDifficultyIcon(int $value): string
{
    $value = max(0, min(10, $value));
    return contentPackDifficultyOptions()[$value][1];
}

function renderContentPackDifficultyPicker(int $selected): void
{
    $options = contentPackDifficultyOptions();
    $selected = array_key_exists($selected, $options) ? $selected : 0;
    [$selectedLabel, $selectedIcon] = $options[$selected];

    echo '<details class="mc-dropdown" style="margin-top:8px;width:100%">';
    echo '<summary style="list-style:none;cursor:pointer;display:flex;align-items:center;gap:10px;min-height:52px;padding:6px 10px;border:1px solid rgba(127,140,163,.32);border-radius:9px;background:rgba(127,140,163,.08);box-sizing:border-box">';
    echo '<img data-difficulty-preview src="'.h($selectedIcon).'" width="40" height="40" alt="">';
    echo '<span style="flex:1;min-width:0"><strong>Give Rate</strong><small class="muted" style="display:block" data-difficulty-selected>'.h($selectedLabel).'</small></span>';
    echo '<span aria-hidden="true">▾</span>';
    echo '</summary>';

    echo '<div style="margin-top:5px;padding:5px;border:1px solid rgba(127,140,163,.30);border-radius:9px;background:var(--card-bg,#111827);box-sizing:border-box">';
    foreach ($options as $value => [$label, $icon]) {
        $checked = $selected === $value;
        echo '<label style="display:flex;align-items:center;gap:10px;width:100%;min-height:56px;padding:5px 8px;margin:2px 0;border:1px solid '.($checked ? 'rgba(127,140,163,.45)' : 'transparent').';border-radius:8px;background:'.($checked ? 'rgba(127,140,163,.14)' : 'transparent').';cursor:pointer;box-sizing:border-box">';
        echo '<input type="radio" name="difficulty" value="'.$value.'" '.($checked ? 'checked' : '').' data-icon="'.h($icon).'" data-label="'.h($label).'" onchange="const d=this.closest(\'details\');d.querySelector(\'[data-difficulty-preview]\').src=this.dataset.icon;d.querySelector(\'[data-difficulty-selected]\').textContent=this.dataset.label;d.open=false;" style="margin:0">';
        echo '<img src="'.h($icon).'" width="44" height="44" alt="">';
        echo '<span style="font-weight:700">'.h($label).'</span>';
        echo '</label>';
    }
    echo '</div>';
    echo '</details>';
}

function contentPackColorPresets(): array
{
    return [
        0 => '#f0f0f0',
        1 => '#ff3b30',
        2 => '#ff9500',
        3 => '#ffcc00',
        4 => '#34c759',
        5 => '#00c7be',
        6 => '#30a9ff',
        7 => '#5856d6',
        8 => '#af52de',
        9 => '#ff2d55',
        10 => '#8e8e93',
        11 => '#5ac8fa',
        12 => '#64d2ff',
        13 => '#bf5af2',
        14 => '#ff375f',
        15 => '#a2845e',
        16 => '#30d158',
        17 => '#ffd60a',
        18 => '#ff9f0a',
        19 => '#ff453a',
        20 => '#64d2ff',
        21 => '#0a84ff',
        22 => '#5e5ce6',
        23 => '#bf5af2',
        24 => '#ff375f',
    ];
}

function renderContentPackColorPicker(string $field, int $selected): void
{
    $presets = contentPackColorPresets();
    $selected = array_key_exists($selected, $presets) ? $selected : 0;

    echo '<details class="mc-dropdown" style="margin-top:8px;width:100%">';
    echo '<summary style="list-style:none;cursor:pointer;display:flex;align-items:center;gap:10px;min-height:52px;padding:6px 10px;border:1px solid rgba(127,140,163,.32);border-radius:9px;background:rgba(127,140,163,.08);box-sizing:border-box">';
    echo '<span data-color-preview style="width:38px;height:38px;flex:0 0 38px;border-radius:9px;border:2px solid rgba(255,255,255,.4);background:'.$presets[$selected].';display:block"></span>';
    echo '<span style="flex:1;min-width:0"><strong>'.h(ucwords(str_replace('_', ' ', $field))).'</strong><small class="muted" style="display:block">Color '.$selected.'</small></span>';
    echo '<span aria-hidden="true">▾</span>';
    echo '</summary>';

    echo '<div style="margin-top:5px;padding:8px;border:1px solid rgba(127,140,163,.30);border-radius:9px;background:var(--card-bg,#111827);box-shadow:0 10px 24px rgba(0,0,0,.28);max-height:300px;overflow:auto">';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(76px,1fr));gap:6px">';

    foreach ($presets as $value => $hex) {
        $checked = $selected === $value;
        echo '<label style="display:flex;align-items:center;gap:6px;padding:5px;border:1px solid '.($checked ? 'rgba(127,140,163,.55)' : 'transparent').';border-radius:8px;background:'.($checked ? 'rgba(127,140,163,.14)' : 'transparent').';cursor:pointer;box-sizing:border-box">';
        echo '<input type="radio" name="'.h($field).'" value="'.$value.'" '.($checked ? 'checked' : '').' data-color="'.$hex.'" onchange="const d=this.closest(\'details\');d.querySelector(\'[data-color-preview]\').style.background=this.dataset.color;d.querySelector(\'summary small\').textContent=\'Color \'+this.value;d.open=false;" style="margin:0">';
        echo '<span style="width:28px;height:28px;flex:0 0 28px;border-radius:7px;background:'.$hex.';border:1px solid rgba(255,255,255,.35);display:block"></span>';
        echo '<span style="font-size:11px;font-weight:700">Color '.$value.'</span>';
        echo '</label>';
    }

    echo '</div>';
    echo '<small class="muted" style="display:block;margin-top:7px">Choose the color visually; the numeric Color ID is submitted automatically.</small>';
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
