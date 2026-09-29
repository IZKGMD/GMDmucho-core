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
echo '<label>Difficulty<input type="number" name="difficulty" min="0" max="10" value="1"></label>';
echo '<label>Color 1<input type="number" name="color1" min="0" max="255" value="0"></label>';
echo '<label>Color 2<input type="number" name="color2" min="0" max="255" value="3"></label>';
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
        echo '<label>Difficulty<input type="number" name="difficulty" min="0" max="10" value="'.h($m['difficulty']).'"></label>';
        echo '</div>';
        echo '<div class="grid" style="margin-top:8px">';
        echo '<label>Color 1<input type="number" name="color1" min="0" max="255" value="'.h($m['color1']).'"></label>';
        echo '<label>Color 2<input type="number" name="color2" min="0" max="255" value="'.h($m['color2']).'"></label>';
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

echo '<p class="muted" style="margin-top:16px">Gauntlets require exactly 5 unique levels. Map Packs accept any number of unique levels. The client receives numeric IDs in the standard GD wire format.</p>';
