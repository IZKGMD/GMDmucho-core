<?php
declare(strict_types=1);

/*
 * MuchoCore Admin — Rating Studio
 * Copyright (C) 2026 IZK
 */

requireRank(20);

$q = trim((string)($_GET['q'] ?? ''));
$selectedId = max(0, (int)($_GET['id'] ?? 0));

$starsOptions = range(0, 10);
$featureOptions = [
    0 => ['None', 'No feature tier'],
    1 => ['Featured', 'Featured'],
    2 => ['Epic', 'Epic'],
    3 => ['Legendary', 'Legendary'],
    4 => ['Mythic', 'Mythic'],
];
$difficultyOptions = [
    0 => 'Unrated',
    1 => 'Auto',
    2 => 'Easy',
    3 => 'Normal',
    4 => 'Hard',
    5 => 'Harder',
    6 => 'Insane',
];
$demonOptions = [
    1 => 'Easy',
    2 => 'Medium',
    3 => 'Hard',
    4 => 'Insane',
    5 => 'Extreme',
    6 => 'Insane+',
    7 => 'Extreme+',
    8 => 'Legacy',
];

$selected = null;

if ($selectedId > 0) {
    $st = $db->prepare(
        'SELECT
            l.*,
            COALESCE(a.username, "-") AS creator_name,
            COALESCE(p.creator_points, 0) AS creator_points
         FROM levels l
         LEFT JOIN accounts a ON a.account_id = l.account_id
         LEFT JOIN profiles p ON p.account_id = l.account_id
         WHERE l.level_id = :id
         LIMIT 1'
    );
    $st->execute(['id' => $selectedId]);
    $selected = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$searchRows = [];
try {
    if ($q !== '') {
        $st = $db->prepare(
            'SELECT
                l.level_id,
                l.name,
                l.stars,
                l.difficulty,
                l.demon,
                l.demon_difficulty,
                l.featured,
                l.epic,
                l.requested_stars,
                l.downloads,
                l.likes,
                l.is_deleted,
                COALESCE(a.username, "-") AS creator_name
             FROM levels l
             LEFT JOIN accounts a ON a.account_id = l.account_id
             WHERE l.name LIKE :q
                OR CAST(l.level_id AS CHAR) = :id
                OR a.username LIKE :creator
             ORDER BY
                (l.requested_stars > 0) DESC,
                l.level_id DESC
             LIMIT 40'
        );
        $st->execute([
            'q' => '%'.$q.'%',
            'id' => ctype_digit($q) ? $q : '',
            'creator' => '%'.$q.'%',
        ]);
        $searchRows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $searchRows = $db->query(
            'SELECT
                l.level_id,
                l.name,
                l.stars,
                l.difficulty,
                l.demon,
                l.demon_difficulty,
                l.featured,
                l.epic,
                l.requested_stars,
                l.downloads,
                l.likes,
                l.is_deleted,
                COALESCE(a.username, "-") AS creator_name
             FROM levels l
             LEFT JOIN accounts a ON a.account_id = l.account_id
             ORDER BY
                (l.requested_stars > 0) DESC,
                l.level_id DESC
             LIMIT 40'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable) {
    $searchRows = [];
}

if (!$selected && $selectedId > 0 && $searchRows) {
    foreach ($searchRows as $row) {
        if ((int)$row['level_id'] === $selectedId) {
            $selected = $row;
            break;
        }
    }
}

$featureForRow = static function (array $row): int {
    return match (true) {
        (int)($row['epic'] ?? 0) >= 3 => 4,
        (int)($row['epic'] ?? 0) === 2 => 3,
        (int)($row['epic'] ?? 0) === 1 => 2,
        (int)($row['featured'] ?? 0) > 0 => 1,
        default => 0,
    };
};

$difficultyLabel = static function (int $difficulty, bool $demon, bool $auto): string {
    if ($demon) {
        return 'Demon';
    }
    if ($auto) {
        return 'Auto';
    }
    return match ($difficulty) {
        1 => 'Auto',
        2 => 'Easy',
        3 => 'Normal',
        4 => 'Hard',
        5 => 'Harder',
        6 => 'Insane',
        default => 'Unrated',
    };
};

$starsDisplay = static function (int $stars): string {
    if ($stars <= 0) {
        return 'Unrated';
    }
    return str_repeat('★', min(10, $stars));
};

$pendingCount = 0;
try {
    $pendingCount = (int)$db->query(
        'SELECT COUNT(*) FROM levels WHERE requested_stars > 0 AND is_deleted = 0'
    )->fetchColumn();
} catch (Throwable) {
}

$ratedCount = 0;
try {
    $ratedCount = (int)$db->query(
        'SELECT COUNT(*) FROM levels WHERE stars > 0 AND is_deleted = 0'
    )->fetchColumn();
} catch (Throwable) {
}

$featuredCount = 0;
try {
    $featuredCount = (int)$db->query(
        'SELECT COUNT(*) FROM levels WHERE featured > 0 AND is_deleted = 0'
    )->fetchColumn();
} catch (Throwable) {
}

echo '<style>
.rating-hero{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(260px,.7fr);gap:14px;margin-bottom:14px}
.rating-hero-main{position:relative;overflow:hidden;padding:22px;border:1px solid #2c3550;border-radius:18px;background:
radial-gradient(circle at 86% 10%,rgba(121,101,255,.22),transparent 35%),
linear-gradient(135deg,#151826,#10151f)}
.rating-hero-main:after{content:"";position:absolute;width:180px;height:180px;border-radius:50%;right:-75px;bottom:-95px;border:1px solid rgba(151,137,255,.15)}
.rating-kicker{font-size:10px;font-weight:850;letter-spacing:.14em;text-transform:uppercase;color:#9a8eff}
.rating-hero h2{font-size:28px;letter-spacing:-.8px;margin:7px 0 7px}
.rating-hero p{margin:0;color:#98a4b8;max-width:720px;line-height:1.6}
.rating-hero-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
.rating-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:9px}
.rating-stat{padding:15px 14px;border:1px solid #252e40;border-radius:14px;background:#0f141c}
.rating-stat b{font-size:23px;display:block;letter-spacing:-.4px}
.rating-stat span{font-size:11px;color:#7f8ba0}
.rating-layout{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(360px,.85fr);gap:14px;align-items:start}
.rating-list,.rating-editor{min-width:0}
.rating-toolbar{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:10px}
.rating-search{display:flex;gap:8px;min-width:min(100%,520px);flex:1}
.rating-search input{flex:1;min-width:150px}
.rating-queue-note{font-size:11px;color:#748096}
.rating-list-card{overflow:hidden;padding:0}
.rating-row{display:grid;grid-template-columns:52px minmax(0,1fr) auto;gap:12px;align-items:center;padding:12px 14px;border-bottom:1px solid #202837;transition:.14s;background:#111620}
.rating-row:last-child{border-bottom:0}
.rating-row:hover{background:#151c27}
.rating-row.on{background:linear-gradient(90deg,rgba(118,100,255,.16),rgba(118,100,255,.04));box-shadow:inset 3px 0 0 #7b68ff}
.rating-level-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:linear-gradient(145deg,#282148,#191d2d);border:1px solid #3b3262;color:#b8afff;font-weight:900}
.rating-row-main{min-width:0}
.rating-row-name{display:flex;align-items:center;gap:7px;min-width:0}
.rating-row-name b{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rating-row-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;color:#778398;font-size:11px}
.rating-row-right{text-align:right}
.rating-stars{font-size:12px;white-space:nowrap;color:#ffd56b;letter-spacing:1px}
.rating-stars.empty{color:#566176;letter-spacing:0}
.rating-request{margin-top:4px;font-size:10px;color:#9f91ff}
.rating-editor-card{padding:18px}
.rating-editor-head{display:flex;justify-content:space-between;gap:12px;align-items:start;margin-bottom:16px}
.rating-editor-title h2{margin:0;font-size:21px;letter-spacing:-.45px}
.rating-editor-title p{margin:5px 0 0;color:#7e8a9f;font-size:12px}
.rating-id{font:12px ui-monospace,SFMono-Regular,Menlo,monospace;color:#6f7b90;background:#0b0f16;border:1px solid #242d3c;padding:7px 9px;border-radius:9px}
.rating-form{display:grid;gap:13px}
.rating-section{padding:14px;border:1px solid #242d3c;border-radius:13px;background:#0d121a}
.rating-section-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.rating-section-title b{font-size:12px}
.rating-section-title span{font-size:10px;color:#6e7a8f;text-transform:uppercase;letter-spacing:.08em}
.rating-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.rating-field{display:grid;gap:6px}
.rating-field.full{grid-column:1/-1}
.rating-field label{font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:#718096;font-weight:800}
.rating-field select,.rating-field input{width:100%;min-height:40px}
.rating-stars-pick{display:grid;grid-template-columns:repeat(11,minmax(0,1fr));gap:5px}
.rating-stars-pick button{padding:0;min-height:34px;border:1px solid #293449;background:#111722;color:#77859a;border-radius:8px;font-size:11px}
.rating-stars-pick button.on{background:linear-gradient(135deg,#6f5bf0,#8b78ff);border-color:#8f82ff;color:#fff}
.rating-switches{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.rating-switch{display:flex;align-items:center;gap:8px;padding:11px;border:1px solid #252f40;border-radius:10px;background:#101620}
.rating-switch input{width:17px!important;height:17px!important;margin:0}
.rating-switch span{font-size:12px}
.rating-switch small{display:block;font-size:10px;color:#6e7a8f;margin-top:2px}
.rating-presets{display:flex;gap:7px;flex-wrap:wrap}
.rating-preset{background:#1a2030!important;color:#cbd4e3!important;border:1px solid #2b3548!important;font-size:11px}
.rating-preset:hover{border-color:#5a4fa0!important}
.rating-savebar{position:sticky;bottom:12px;z-index:5;display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px;border:1px solid #35304f;border-radius:12px;background:rgba(13,16,25,.94);backdrop-filter:blur(14px);box-shadow:0 16px 45px rgba(0,0,0,.28)}
.rating-savebar small{color:#7e8a9f}
.rating-savebar button{min-height:42px;padding:9px 16px}
.rating-empty{padding:42px 18px;text-align:center;color:#788499}
.rating-empty b{display:block;color:#dce2ee;margin-bottom:5px}
.rating-mini-badge{display:inline-flex;align-items:center;padding:4px 7px;border-radius:999px;background:#191f2b;border:1px solid #2a3445;color:#8d99ad;font-size:10px}
.rating-mini-badge.pending{color:#c2b7ff;border-color:#473e75;background:#211d36}
.rating-mini-badge.deleted{color:#ff9eaa;border-color:#572933;background:#26171c}
.rating-preview{display:grid;grid-template-columns:48px 1fr auto;gap:11px;align-items:center;padding:11px 0 14px;border-bottom:1px solid #232c3b;margin-bottom:13px}
.rating-preview-icon{width:48px;height:48px;border-radius:13px;display:grid;place-items:center;background:linear-gradient(145deg,#272142,#161c2a);font-weight:900;color:#b9b0ff;font-size:18px}
.rating-preview-main b{display:block;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rating-preview-main small{display:block;margin-top:3px}
.rating-preview-right{text-align:right}
.rating-preview-right strong{display:block;font-size:18px;color:#ffd56c}
.rating-help{padding:11px 12px;border-radius:10px;background:#111823;border:1px dashed #29364b;color:#7d899d;font-size:11px;line-height:1.5}
.rating-help b{color:#bec7d8}
@media(max-width:1050px){.rating-hero,.rating-layout{grid-template-columns:1fr}.rating-stats{grid-template-columns:repeat(3,1fr)}}
@media(max-width:640px){.rating-grid,.rating-switches{grid-template-columns:1fr}.rating-field.full{grid-column:auto}.rating-stars-pick{grid-template-columns:repeat(6,1fr)}.rating-stats{grid-template-columns:1fr 1fr 1fr}.rating-row{grid-template-columns:44px minmax(0,1fr);}.rating-row-right{grid-column:2;text-align:left}.rating-savebar{position:static}.rating-hero h2{font-size:23px}}
</style>';

echo '<section class="rating-hero">';
echo '<div class="rating-hero-main">';
echo '<div class="rating-kicker">Moderator workspace</div>';
echo '<h2>Rating Studio</h2>';
echo '<p>Review level requests, assign stars and difficulty, set a feature tier, and publish a clean rating in one place.</p>';
echo '<div class="rating-hero-actions">';
echo '<a class="btn" href="/admin/?page=moderation&q='.rawurlencode($q).'">Open moderation queue</a>';
echo '<a class="btn gray" href="/admin/?page=levels&q='.rawurlencode($q).'">Open raw level editor</a>';
echo '</div>';
echo '</div>';
echo '<div class="rating-stats">';
echo '<div class="rating-stat"><span>Pending requests</span><b>'.number_format($pendingCount).'</b></div>';
echo '<div class="rating-stat"><span>Rated levels</span><b>'.number_format($ratedCount).'</b></div>';
echo '<div class="rating-stat"><span>Featured+</span><b>'.number_format($featuredCount).'</b></div>';
echo '</div>';
echo '</section>';

echo '<div class="rating-layout">';
echo '<section class="rating-list">';
echo '<div class="rating-toolbar">';
echo '<form class="rating-search" method="get">';
echo '<input type="hidden" name="page" value="rating">';
echo '<input name="q" value="'.h($q).'" placeholder="Search level ID, name, or creator..." autocomplete="off">';
echo '<button type="submit">Search</button>';
echo '</form>';
echo '<span class="rating-queue-note">Showing up to 40 levels</span>';
echo '</div>';

echo '<div class="card rating-list-card">';

if (!$searchRows) {
    echo '<div class="rating-empty"><b>No levels found</b><span>Try a level ID, name, or creator username.</span></div>';
} else {
    foreach ($searchRows as $row) {
        $id = (int)$row['level_id'];
        $active = $selected && $id === (int)$selected['level_id'];
        $feature = $featureForRow($row);
        $difficulty = $difficultyLabel(
            (int)($row['difficulty'] ?? 0),
            (bool)$row['demon'],
            false
        );
        $pending = (int)($row['requested_stars'] ?? 0) > 0;
        $deleted = (int)($row['is_deleted'] ?? 0) === 1;
        $label = trim((string)($row['name'] ?? '')) ?: 'Unnamed level';

        echo '<a class="rating-row '.($active ? 'on' : '').'" href="/admin/?page=rating&id='.$id.'&q='.rawurlencode($q).'">';
        echo '<div class="rating-level-icon">'.h(strtoupper(mb_substr($label, 0, 1, 'UTF-8'))).'</div>';
        echo '<div class="rating-row-main">';
        echo '<div class="rating-row-name"><b>'.h($label).'</b>';
        if ($deleted) echo '<span class="rating-mini-badge deleted">Deleted</span>';
        elseif ($pending) echo '<span class="rating-mini-badge pending">Request</span>';
        echo '</div>';
        echo '<div class="rating-row-meta">';
        echo '<span>#'.$id.'</span>';
        echo '<span>'.h($row['creator_name'] ?? '-').'</span>';
        echo '<span>'.h($difficulty).'</span>';
        if ($feature > 0) echo '<span>'.h($featureOptions[$feature][0]).'</span>';
        echo '</div>';
        echo '</div>';
        echo '<div class="rating-row-right">';
        echo '<div class="rating-stars '.((int)$row['stars'] > 0 ? '' : 'empty').'">';
        echo ((int)$row['stars'] > 0 ? h($starsDisplay((int)$row['stars'])) : 'Not rated');
        echo '</div>';
        if ($pending) echo '<div class="rating-request">requested '.(int)$row['requested_stars'].'★</div>';
        echo '</div>';
        echo '</a>';
    }
}

echo '</div>';
echo '</section>';

echo '<section class="rating-editor">';

if (!$selected) {
    echo '<div class="card rating-editor-card">';
    echo '<div class="rating-empty" style="padding:28px 10px">';
    echo '<b>Select a level to rate</b>';
    echo '<span>Pick any level from the queue on the left. Pending requests are surfaced first.</span>';
    echo '</div>';
    echo '</div>';
} else {
    $selectedStars = max(0, min(10, (int)($selected['stars'] ?? 0)));
    $selectedFeature = $featureForRow($selected);
    $selectedDemon = (int)($selected['demon'] ?? 0) === 1;
    $selectedAuto = (int)($selected['auto_level'] ?? 0) === 1;
    $selectedDifficulty = max(0, min(6, (int)($selected['difficulty'] ?? 0)));
    $selectedDemonDifficulty = max(0, min(8, (int)($selected['demon_difficulty'] ?? 0)));
    $selectedName = trim((string)($selected['name'] ?? '')) ?: 'Unnamed level';

    echo '<div class="card rating-editor-card">';
    echo '<div class="rating-editor-head">';
    echo '<div class="rating-editor-title"><h2>'.h($selectedName).'</h2><p>Created by '.h($selected['creator_name'] ?? '-').' · #'.(int)$selected['level_id'].'</p></div>';
    echo '<div class="rating-id">ID '.(int)$selected['level_id'].'</div>';
    echo '</div>';

    echo '<div class="rating-preview">';
    echo '<div class="rating-preview-icon">'.h(strtoupper(mb_substr($selectedName, 0, 1, 'UTF-8'))).'</div>';
    echo '<div class="rating-preview-main">';
    echo '<b>'.h($selectedName).'</b>';
    echo '<small>'.number_format((int)($selected['downloads'] ?? 0)).' downloads · '.number_format((int)($selected['likes'] ?? 0)).' likes · Creator CP '.number_format((int)($selected['creator_points'] ?? 0)).'</small>';
    echo '</div>';
    echo '<div class="rating-preview-right"><strong>'.($selectedStars > 0 ? $selectedStars.'★' : '—').'</strong><small>'.($selectedStars > 0 ? 'Current rating' : 'Unrated').'</small></div>';
    echo '</div>';

    echo '<form method="post" class="rating-form" id="ratingForm">';
    echo '<input type="hidden" name="csrf" value="'.csrf().'">';
    echo '<input type="hidden" name="action" value="level-rate-save">';
    echo '<input type="hidden" name="return" value="rating">';
    echo '<input type="hidden" name="id" value="'.(int)$selected['level_id'].'">';
    echo '<input type="hidden" name="return_q" value="'.h($q).'">';

    echo '<div class="rating-section">';
    echo '<div class="rating-section-title"><b>Star rating</b><span>0–10 stars</span></div>';
    echo '<div class="rating-stars-pick" id="starsPicker">';
    foreach ($starsOptions as $star) {
        $active = $selectedStars === $star ? ' on' : '';
        echo '<button type="button" class="'.$active.'" data-stars="'.$star.'">'.($star === 0 ? '0' : $star.'★').'</button>';
    }
    echo '</div>';
    echo '<input type="hidden" name="stars" id="starsValue" value="'.$selectedStars.'">';
    echo '</div>';

    echo '<div class="rating-section">';
    echo '<div class="rating-section-title"><b>Classification</b><span>Difficulty & feature</span></div>';
    echo '<div class="rating-grid">';

    echo '<div class="rating-field">';
    echo '<label for="difficulty">Difficulty</label>';
    echo '<select name="difficulty" id="difficulty">';
    foreach ($difficultyOptions as $value => $label) {
        echo '<option value="'.$value.'"'.($selectedDifficulty === $value ? ' selected' : '').'>'.h($label).'</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="rating-field">';
    echo '<label for="feature">Feature tier</label>';
    echo '<select name="feature" id="feature">';
    foreach ($featureOptions as $value => $pair) {
        echo '<option value="'.$value.'"'.($selectedFeature === $value ? ' selected' : '').'>'.h($pair[0]).'</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="rating-field full">';
    echo '<label>Quick presets</label>';
    echo '<div class="rating-presets">';
    echo '<button type="button" class="rating-preset" data-preset="unrated">Clear rating</button>';
    echo '<button type="button" class="rating-preset" data-preset="easy">Easy 2★</button>';
    echo '<button type="button" class="rating-preset" data-preset="normal">Normal 3★</button>';
    echo '<button type="button" class="rating-preset" data-preset="hard">Hard 5★</button>';
    echo '<button type="button" class="rating-preset" data-preset="harder">Harder 7★</button>';
    echo '<button type="button" class="rating-preset" data-preset="insane">Insane 9★</button>';
    echo '<button type="button" class="rating-preset" data-preset="demon">Demon 10★</button>';
    echo '</div>';
    echo '</div>';

    echo '</div>';
    echo '</div>';

    echo '<div class="rating-section">';
    echo '<div class="rating-section-title"><b>Special classification</b><span>Optional</span></div>';
    echo '<div class="rating-switches">';
    echo '<label class="rating-switch"><input type="checkbox" name="auto_level" id="autoLevel" '.($selectedAuto ? 'checked' : '').'><span><b>Auto level</b><small>Marks the level as Auto</small></span></label>';
    echo '<label class="rating-switch"><input type="checkbox" name="demon" id="demon" '.($selectedDemon ? 'checked' : '').'><span><b>Demon</b><small>Enables demon difficulty</small></span></label>';
    echo '</div>';

    echo '<div class="rating-grid" style="margin-top:10px">';
    echo '<div class="rating-field full" id="demonDifficultyWrap" style="'.($selectedDemon ? '' : 'display:none').'">';
    echo '<label for="demonDifficulty">Demon difficulty</label>';
    echo '<select name="demon_difficulty" id="demonDifficulty">';
    echo '<option value="0">Not set</option>';
    foreach ($demonOptions as $value => $label) {
        echo '<option value="'.$value.'"'.($selectedDemonDifficulty === $value ? ' selected' : '').'>'.h($value.' — '.$label).'</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '<div class="rating-help"><b>Publishing a rating</b> saves the level classification, clears the pending star request, recalculates the creator’s Creator Points, and writes an admin audit event.</div>';

    echo '<div class="rating-savebar">';
    echo '<small>Changes affect the public level immediately after save.</small>';
    echo '<button type="submit">Publish rating</button>';
    echo '</div>';

    echo '</form>';
    echo '</div>';
}

echo '</section>';
echo '</div>';

echo '<script>
(() => {
    const starsValue = document.getElementById("starsValue");
    const picker = document.getElementById("starsPicker");
    const difficulty = document.getElementById("difficulty");
    const demon = document.getElementById("demon");
    const demonWrap = document.getElementById("demonDifficultyWrap");
    const feature = document.getElementById("feature");
    const autoLevel = document.getElementById("autoLevel");

    const setStars = value => {
        if (!starsValue || !picker) return;
        starsValue.value = String(value);
        picker.querySelectorAll("[data-stars]").forEach(btn => {
            btn.classList.toggle("on", Number(btn.dataset.stars) === Number(value));
        });
    };

    picker?.querySelectorAll("[data-stars]").forEach(btn => {
        btn.addEventListener("click", () => setStars(btn.dataset.stars));
    });

    const syncDemon = () => {
        if (!demon || !demonWrap || !difficulty) return;
        demonWrap.style.display = demon.checked ? "" : "none";
        if (demon.checked) {
            difficulty.value = "6";
            if (autoLevel) autoLevel.checked = false;
        }
    };

    demon?.addEventListener("change", syncDemon);
    autoLevel?.addEventListener("change", () => {
        if (autoLevel.checked && demon) {
            demon.checked = false;
            syncDemon();
            difficulty.value = "1";
            setStars(1);
        }
    });

    document.querySelectorAll("[data-preset]").forEach(btn => {
        btn.addEventListener("click", () => {
            const preset = btn.dataset.preset;
            if (preset === "unrated") {
                setStars(0);
                difficulty.value = "0";
                feature.value = "0";
                if (demon) demon.checked = false;
                if (autoLevel) autoLevel.checked = false;
                if (demonWrap) demonWrap.style.display = "none";
            } else if (preset === "easy") {
                setStars(2); difficulty.value = "2"; feature.value = "0";
            } else if (preset === "normal") {
                setStars(3); difficulty.value = "3"; feature.value = "0";
            } else if (preset === "hard") {
                setStars(5); difficulty.value = "4"; feature.value = "0";
            } else if (preset === "harder") {
                setStars(7); difficulty.value = "5"; feature.value = "0";
            } else if (preset === "insane") {
                setStars(9); difficulty.value = "6"; feature.value = "0";
            } else if (preset === "demon") {
                setStars(10); difficulty.value = "6"; feature.value = "0";
                if (demon) demon.checked = true;
                if (autoLevel) autoLevel.checked = false;
                if (demonWrap) demonWrap.style.display = "";
            }
        });
    });

    document.getElementById("ratingForm")?.addEventListener("submit", event => {
        const current = Number(starsValue?.value || 0);
        const isDemon = !!demon?.checked;
        if (isDemon && current < 10) {
            if (!confirm("Demon is selected with fewer than 10 stars. Publish anyway?")) {
                event.preventDefault();
            }
        }
    });
})();
</script>';
