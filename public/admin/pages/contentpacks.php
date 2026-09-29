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
        0 => ['Auto', '#64748b'],
        1 => ['Easy', '#43c36b'],
        2 => ['Normal', '#6cc24a'],
        3 => ['Hard', '#f2c94c'],
        4 => ['Harder', '#f2994a'],
        5 => ['Insane', '#eb5757'],
        6 => ['Easy Demon', '#c95cff'],
        7 => ['Medium Demon', '#b43cff'],
        8 => ['Hard Demon', '#a12cff'],
        9 => ['Insane Demon', '#8b20e8'],
        10 => ['Extreme Demon', '#6d16ba'],
    ];
}

function contentPackDifficultyIcon(int $value, string $accent): string
{
    $eyes = match ($value) {
        0 => '<circle cx="31" cy="29" r="3" fill="white"/><circle cx="49" cy="29" r="3" fill="white"/>',
        1, 2 => '<circle cx="30" cy="29" r="3.5" fill="white"/><circle cx="50" cy="29" r="3.5" fill="white"/>',
        3, 4 => '<path d="M26 29l5-4 5 4-5 4zM44 29l5-4 5 4-5 4z" fill="white"/>',
        5 => '<path d="M26 31l6-5 5 5-5 5zM43 31l5-5 6 5-6 5z" fill="white"/>',
        default => '<path d="M27 31l4-6 4 4-4 6zM45 29l4-4 4 6-4 4z" fill="white"/>',
    };

    $mouth = match ($value) {
        0 => '<path d="M33 43h14" stroke="white" stroke-width="2.5" stroke-linecap="round"/>',
        1 => '<path d="M33 41q7 7 14 0" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round"/>',
        2 => '<path d="M34 42q6 2 12 0" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round"/>',
        3 => '<path d="M34 43q6-5 12 0" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round"/>',
        4, 5 => '<path d="M34 45q6-7 12 0" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round"/>',
        default => '<path d="M35 44l3 3 4-6 4 6" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>',
    };

    $horns = $value >= 6
        ? '<path d="M18 22L12 11l11 7M62 22l6-11-11 7" fill="'.$accent.'" stroke="#ffffff" stroke-opacity=".45" stroke-width="2" stroke-linejoin="round"/>'
        : '';

    return '<svg width="54" height="54" viewBox="0 0 80 80" aria-hidden="true">'
        .$horns
        .'<path d="M22 17L40 10l18 7 8 17-3 25-23 11-23-11-3-25z" fill="'.$accent.'" stroke="rgba(255,255,255,.72)" stroke-width="3" stroke-linejoin="round"/>'
        .'<path d="M28 18l12-5 12 5" fill="none" stroke="rgba(255,255,255,.4)" stroke-width="2"/>'
        .$eyes
        .$mouth
        .'</svg>';
}

function renderContentPackDifficultyPicker(int $selected): void
{
    $options = contentPackDifficultyOptions();

    echo '<div class="mc-picker" style="margin-top:10px">';
    echo '<div style="display:flex;justify-content:space-between;align-items:end;gap:8px;margin-bottom:8px">';
    echo '<div><strong style="font-size:14px">Give Rate</strong><div class="muted" style="font-size:12px;margin-top:2px">Choose the difficulty icon</div></div>';
    echo '<span class="muted" style="font-size:12px">Selected: <strong data-difficulty-label>'.h($options[$selected][0] ?? 'Auto').'</strong></span>';
    echo '</div>';
    echo '<input type="hidden" name="difficulty" value="'.h($selected).'">';
    echo '<div role="radiogroup" aria-label="Difficulty" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(72px,1fr));gap:7px">';

    foreach ($options as $value => [$label, $accent]) {
        $isSelected = $selected === $value;
        $selectedStyle = $isSelected
            ? 'outline:2px solid '.$accent.';box-shadow:0 5px 14px rgba(0,0,0,.22);transform:translateY(-2px);'
            : '';
        echo '<button type="button" class="mc-rate-option" data-value="'.$value.'" data-label="'.h($label).'" '
            .'aria-pressed="'.($isSelected ? 'true' : 'false').'" '
            .'title="'.h($label).'" onclick="muchoPickDifficulty(this)" '
            .'style="min-height:78px;padding:5px 4px;border:1px solid rgba(127,140,163,.25);'
            .'border-radius:10px;background:linear-gradient(180deg,rgba(255,255,255,.055),rgba(0,0,0,.10));'
            .'color:inherit;cursor:pointer;'.$selectedStyle.'">';
        echo '<span style="display:flex;justify-content:center;align-items:center;height:55px">'.$
            contentPackDifficultyIcon((int)$value, $accent).'</span>';
        echo '<span style="display:block;font-size:10px;font-weight:800;line-height:12px;min-height:24px">'.h($label).'</span>';
        echo '</button>';
    }

    echo '</div>';
    echo '</div>';
}
function contentPackColorPresets(): array
{
    return [
        0 => '#f8fafc',
        1 => '#3b82f6',
        2 => '#22c55e',
        3 => '#06b6d4',
        4 => '#f59e0b',
        5 => '#ef4444',
        6 => '#a855f7',
        7 => '#ec4899',
        8 => '#14b8a6',
        9 => '#eab308',
        10 => '#84cc16',
        11 => '#6366f1',
        12 => '#f97316',
        13 => '#8b5cf6',
        14 => '#0ea5e9',
        15 => '#10b981',
    ];
}

function renderContentPackColorPicker(string $field, int $selected): void
{
    echo '<div style="margin-top:8px">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:7px">';
    echo '<strong style="font-size:13px">'.h(ucwords(str_replace('_', ' ', $field))).'</strong>';
    echo '<span class="muted" style="font-size:12px">Color ID <span data-color-value="'.h($field).'">'.h($selected).'</span></span>';
    echo '</div>';
    echo '<input type="hidden" name="'.h($field).'" value="'.h($selected).'">';
    echo '<div role="radiogroup" aria-label="'.h($field).' color" style="display:flex;flex-wrap:wrap;gap:6px">';
    foreach (contentPackColorPresets() as $value => $hex) {
        $active = $selected === $value
            ? 'box-shadow:0 0 0 2px currentColor;transform:scale(1.05);'
            : '';
        echo '<button type="button" class="mc-color-option" data-field="'.h($field).'" data-value="'.$value.'" '
            .'aria-pressed="'.($selected === $value ? 'true' : 'false').'" '
            .'title="Color ID '.$value.'" '
            .'onclick="muchoPickColor(this)" '
            .'style="width:34px;height:34px;padding:0;border:2px solid rgba(255,255,255,.32);'
            .'border-radius:9px;background:'.$hex.';cursor:pointer;'.$active.'">';
        echo '<span style="font-size:10px;font-weight:800;color:'.($value === 0 ? '#1f2937' : '#fff').';text-shadow:0 1px 2px rgba(0,0,0,.35)">'.$value.'</span>';
        echo '</button>';
    }
    echo '</div>';
    echo '<small class="muted">Palette buttons are a visual shortcut; the server still stores the numeric Color ID used by the GD protocol.</small>';
    echo '</div>';
}

function renderContentPackPickerScript(): void
{
    echo <<<'HTML'
<script>
function muchoPickDifficulty(button) {
    const form = button.closest('form');
    const input = form.querySelector('input[name="difficulty"]');
    if (!input) return;

    input.value = button.dataset.value;

    const label = form.querySelector('[data-difficulty-label]');
    if (label) label.textContent = button.dataset.label;

    form.querySelectorAll('.mc-rate-option').forEach((item) => {
        const active = item === button;
        item.setAttribute('aria-pressed', active ? 'true' : 'false');
        item.style.outline = active ? '2px solid currentColor' : '';
        item.style.boxShadow = active ? '0 5px 14px rgba(0,0,0,.22)' : '';
        item.style.transform = active ? 'translateY(-2px)' : '';
    });
}

function muchoPickColor(button) {
    const form = button.closest('form');
    const field = button.dataset.field;
    const input = form.querySelector('input[name="' + field + '"]');
    if (!input) return;
    input.value = button.dataset.value;
    const valueLabel = form.querySelector('[data-color-value="' + field + '"]');
    if (valueLabel) valueLabel.textContent = button.dataset.value;
    form.querySelectorAll('.mc-color-option[data-field="' + field + '"]').forEach((item) => {
        const active = item === button;
        item.setAttribute('aria-pressed', active ? 'true' : 'false');
        item.style.boxShadow = active ? '0 0 0 2px currentColor' : '';
        item.style.transform = active ? 'scale(1.05)' : '';
    });
}
</script>
HTML;
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

renderContentPackPickerScript();
echo '<p class="muted" style="margin-top:16px">Gauntlets require exactly 5 unique levels. Map Packs accept any number of unique levels. Give Rate and color controls are clickable; the stored values remain GD-compatible numeric IDs.</p>';
