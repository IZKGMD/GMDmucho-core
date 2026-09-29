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

function contentPackDifficultyIcon(int $value): string
{
    $value = max(0, min(10, $value));
    $files = [
        0 => '00-auto.svg',
        1 => '01-easy.svg',
        2 => '02-normal.svg',
        3 => '03-hard.svg',
        4 => '04-harder.svg',
        5 => '05-insane.svg',
        6 => '06-easy-demon.svg',
        7 => '07-medium-demon.svg',
        8 => '08-hard-demon.svg',
        9 => '09-insane-demon.svg',
        10 => '10-extreme-demon.svg',
    ];

    return '/assets/difficulties/'.$files[$value];
}
function renderContentPackDifficultyPicker(int $selected): void
{
    $options = contentPackDifficultyOptions();
    $selected = array_key_exists($selected, $options) ? $selected : 0;
    [$selectedLabel] = $options[$selected];

    echo '<div class="mc-field-picker" data-picker="difficulty" style="margin-top:8px;position:relative">';
    echo '<label style="display:block">Give Rate';
    echo '<input type="hidden" name="difficulty" value="'.h($selected).'">';
    echo '<button type="button" class="mc-select-field" data-picker-toggle style="width:100%;min-height:52px;display:flex;align-items:center;gap:10px;padding:6px 10px;text-align:left;border:1px solid rgba(127,140,163,.32);border-radius:10px;background:rgba(127,140,163,.08);color:inherit;cursor:pointer">';
    echo '<img data-picker-preview src="'.h(contentPackDifficultyIcon($selected)).'" width="42" height="42" alt="">';
    echo '<span style="flex:1"><strong data-difficulty-label>'.h($selectedLabel).'</strong><small class="muted" style="display:block">Click to choose difficulty</small></span>';
    echo '<span aria-hidden="true" style="font-size:18px">▾</span>';
    echo '</button>';
    echo '</label>';
    echo '<div class="mc-select-menu" data-picker-menu hidden style="position:absolute;z-index:50;left:0;right:0;margin-top:6px;padding:10px;border:1px solid rgba(127,140,163,.34);border-radius:12px;background:var(--card-bg,#111827);box-shadow:0 12px 30px rgba(0,0,0,.35)">';
    echo '<div role="radiogroup" aria-label="Difficulty" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(72px,1fr));gap:7px">';
    foreach ($options as $value => [$label, $accent]) {
        $isSelected = $selected === $value;
        echo '<button type="button" class="mc-rate-option" data-value="'.$value.'" data-label="'.h($label).'" data-icon="'.h(contentPackDifficultyIcon((int)$value)).'" '
            .'aria-pressed="'.($isSelected ? 'true' : 'false').'" title="'.h($label).'" onclick="muchoPickDifficulty(this)" '
            .'style="min-height:84px;padding:5px 3px;border:1px solid rgba(127,140,163,.25);border-radius:10px;background:rgba(127,140,163,.08);color:inherit;cursor:pointer;transition:.12s">';
        echo '<img src="'.h(contentPackDifficultyIcon((int)$value)).'" width="58" height="58" alt="">';
        echo '<span style="display:block;font-size:10px;font-weight:800;line-height:12px">'.h($label).'</span>';
        echo '</button>';
    }
    echo '</div></div></div>';
}

function renderContentPackColorPicker(string $field, int $selected): void
{
    $presets = contentPackColorPresets();
    $selected = array_key_exists($selected, $presets) ? $selected : array_key_first($presets);

    echo '<div class="mc-field-picker" data-picker="'.h($field).'" style="margin-top:8px;position:relative">';
    echo '<label style="display:block">'.h(ucwords(str_replace('_', ' ', $field)));
    echo '<input type="hidden" name="'.h($field).'" value="'.h($selected).'">';
    echo '<button type="button" class="mc-select-field" data-picker-toggle style="width:100%;min-height:52px;display:flex;align-items:center;gap:10px;padding:6px 10px;text-align:left;border:1px solid rgba(127,140,163,.32);border-radius:10px;background:rgba(127,140,163,.08);color:inherit;cursor:pointer">';
    echo '<span data-picker-color-preview style="width:36px;height:36px;border-radius:9px;border:2px solid rgba(255,255,255,.3);background:'.$presets[$selected].';display:block"></span>';
    echo '<span style="flex:1"><strong data-color-value="'.h($field).'">Color '.$selected.'</strong><small class="muted" style="display:block">Click to choose color</small></span>';
    echo '<span aria-hidden="true" style="font-size:18px">▾</span>';
    echo '</button>';
    echo '</label>';
    echo '<div class="mc-select-menu" data-picker-menu hidden style="position:absolute;z-index:50;left:0;right:0;margin-top:6px;padding:10px;border:1px solid rgba(127,140,163,.34);border-radius:12px;background:var(--card-bg,#111827);box-shadow:0 12px 30px rgba(0,0,0,.35)">';
    echo '<div role="radiogroup" aria-label="'.h($field).' color" style="display:flex;flex-wrap:wrap;gap:7px">';
    foreach ($presets as $value => $hex) {
        $isSelected = $selected === $value;
        $textColor = $value === 0 ? '#1f2937' : '#fff';
        echo '<button type="button" class="mc-color-option" data-field="'.h($field).'" data-value="'.$value.'" data-hex="'.h($hex).'" aria-pressed="'.($isSelected ? 'true' : 'false').'" title="Color ID '.$value.'" onclick="muchoPickColor(this)" style="width:48px;height:48px;padding:2px;border:0;background:transparent;border-radius:12px;cursor:pointer;transition:.12s">';
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="42" height="42" viewBox="0 0 42 42" aria-hidden="true"><rect x="2" y="2" width="38" height="38" rx="10" fill="'.$hex.'" stroke="rgba(255,255,255,.55)" stroke-width="2"/><text x="21" y="27" text-anchor="middle" font-size="10" font-weight="800" fill="'.$textColor.'">'.$value.'</text></svg>';
        echo '</button>';
    }
    echo '</div></div></div>';
}


function renderContentPackPickerScript(): void
{
    echo <<<'HTML'
<script>
function muchoClosePickers(except) {
    document.querySelectorAll('.mc-select-menu').forEach((menu) => {
        if (!except || menu !== except) menu.hidden = true;
    });
}

function muchoPickDifficulty(button) {
    const form = button.closest('form');
    const picker = button.closest('[data-picker="difficulty"]');
    const input = form?.querySelector('input[name="difficulty"]');
    if (!picker || !input) return;

    input.value = button.dataset.value;

    const label = picker.querySelector('[data-difficulty-label]');
    const preview = picker.querySelector('[data-picker-preview]');
    if (label) label.textContent = button.dataset.label;
    if (preview) preview.src = button.dataset.icon;

    picker.querySelectorAll('.mc-rate-option').forEach((item) => {
        const active = item === button;
        item.setAttribute('aria-pressed', active ? 'true' : 'false');
        item.style.outline = active ? '2px solid currentColor' : '';
        item.style.transform = active ? 'translateY(-1px)' : '';
    });

    const menu = picker.querySelector('[data-picker-menu]');
    if (menu) menu.hidden = true;
}

function muchoPickColor(button) {
    const form = button.closest('form');
    const field = button.dataset.field;
    const picker = button.closest('[data-picker="' + field + '"]');
    const input = form?.querySelector('input[name="' + field + '"]');
    if (!picker || !input) return;

    input.value = button.dataset.value;

    const valueLabel = picker.querySelector('[data-color-value]');
    const preview = picker.querySelector('[data-picker-color-preview]');
    if (valueLabel) valueLabel.textContent = 'Color ' + button.dataset.value;
    if (preview) preview.style.background = button.dataset.hex;

    picker.querySelectorAll('.mc-color-option').forEach((item) => {
        const active = item === button;
        item.setAttribute('aria-pressed', active ? 'true' : 'false');
        item.style.outline = active ? '2px solid currentColor' : '';
        item.style.transform = active ? 'scale(1.06)' : '';
    });

    const menu = picker.querySelector('[data-picker-menu]');
    if (menu) menu.hidden = true;
}

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-picker-toggle]');
    if (toggle) {
        const picker = toggle.closest('.mc-field-picker');
        const menu = picker?.querySelector('[data-picker-menu]');
        if (!menu) return;
        const wasHidden = menu.hidden;
        muchoClosePickers(menu);
        menu.hidden = !wasHidden;
        return;
    }

    if (!event.target.closest('.mc-field-picker')) {
        muchoClosePickers();
    }
});
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
echo '<p class="muted" style="margin-top:16px">Gauntlets require exactly 5 unique levels. Map Packs accept any number of unique levels. Difficulty and colors are selected visually; the server stores the original numeric GD values.</p>';
