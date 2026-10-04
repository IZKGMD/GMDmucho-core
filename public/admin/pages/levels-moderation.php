<?php
declare(strict_types=1);

/*
 * MuchoCore Admin — Levels & Moderation
 * Extracted verbatim from legacy admin.
 * Copyright (C) 2026 IZK
 */

if (true) {

echo '<div class="section-actions" style="margin-bottom:12px">';
echo '<a class="btn" href="/admin/?page=rating'.(!empty($_GET['q']) ? '&q='.rawurlencode((string)$_GET['q']) : '').'">Open Rating Studio →</a>';
echo '<span class="muted" style="align-self:center">Use Rating Studio for publishing stars, difficulty, feature tiers and Creator Points.</span>';
echo '</div>';

$q=trim((string)($_GET['q'] ?? ''));
$creator=trim((string)($_GET['creator'] ?? ''));
$gameVersion=trim((string)($_GET['game_version'] ?? ''));
$minStars=max(0,min(10,(int)($_GET['min_stars'] ?? 0)));
$featured=isset($_GET['featured']) ? (int)$_GET['featured'] : -1;
$deleted=isset($_GET['deleted']) ? (int)$_GET['deleted'] : -1;
$sort=(string)($_GET['sort'] ?? 'newest');

$sql=
'SELECT
 level_id,name,account_id,stars,difficulty,
 demon,demon_difficulty,featured,epic,
 requested_stars,downloads,likes,is_deleted,
 created_at
 FROM levels';

$args=[];
$where=[];

if ($page==='moderation') {
    $where[]='requested_stars>0';
}
elseif ($q!=='') {
    $where[]='(name LIKE :q OR level_id=:id)';
    $args=[
        'q'=>'%'.$q.'%',
        'id'=>ctype_digit($q)?(int)$q:0
    ];
}

if ($page==='levels') {
    if ($creator!=='') {
        $where[]='EXISTS (
            SELECT 1 FROM accounts search_creator
            WHERE search_creator.account_id=levels.account_id
              AND search_creator.username LIKE :creator
        )';
        $args['creator']='%'.$creator.'%';
    }

    if (ctype_digit($gameVersion) && (int)$gameVersion>0) {
        $where[]='game_version=:game_version';
        $args['game_version']=(int)$gameVersion;
    }

    if ($minStars>0) {
        $where[]='stars>=:min_stars';
        $args['min_stars']=$minStars;
    }

    if ($featured===0 || $featured===1) {
        $where[]='featured=:featured';
        $args['featured']=$featured;
    }

    if ($deleted===0 || $deleted===1) {
        $where[]='is_deleted=:deleted';
        $args['deleted']=$deleted;
    }
}

if ($where!==[]) {
    $sql.=' WHERE '.implode(' AND ',$where);
}

$sql.=' ORDER BY '.match($sort){
    'downloads'=>'downloads DESC, level_id DESC',
    'likes'=>'likes DESC, level_id DESC',
    'stars'=>'stars DESC, level_id DESC',
    default=>'level_id DESC'
}.' LIMIT 150';

$st=$db->prepare($sql);
$st->execute($args);

if ($page==='levels') {
    echo '<form class="search" style="display:grid;grid-template-columns:2fr 1fr 120px 120px 120px 140px;gap:7px;align-items:end">';
    echo '<input type="hidden" name="page" value="levels">';
    echo '<div><small>Name / ID</small><input name="q" value="'.h($q).'" placeholder="Level name / ID"></div>';
    echo '<div><small>Creator</small><input name="creator" value="'.h($creator).'" placeholder="Username"></div>';
    echo '<div><small>GD version</small><input name="game_version" value="'.h($gameVersion).'" placeholder="22"></div>';
    echo '<div><small>Min stars</small><input type="number" min="0" max="10" name="min_stars" value="'.h((string)$minStars).'"></div>';
    echo '<div><small>Featured</small><select name="featured"><option value="-1"'.($featured===-1?' selected':'').'>Any</option><option value="1"'.($featured===1?' selected':'').'>Featured</option><option value="0"'.($featured===0?' selected':'').'>Not featured</option></select></div>';
    echo '<div><small>Sort</small><select name="sort"><option value="newest"'.($sort==='newest'?' selected':'').'>Newest</option><option value="downloads"'.($sort==='downloads'?' selected':'').'>Downloads</option><option value="likes"'.($sort==='likes'?' selected':'').'>Likes</option><option value="stars"'.($sort==='stars'?' selected':'').'>Stars</option></select></div>';
    echo '<button style="grid-column:1/-1;width:max-content">Search levels</button>';
    echo '</form>';
}

echo '<div class="table"><table>';
echo '<tr>
<th>ID</th><th>Name</th><th>Stars</th>
<th>Diff</th><th>Demon</th><th>Demon diff</th>
<th>Featured</th><th>Epic</th><th>Requested</th>
<th>Downloads</th><th>Likes</th><th>Deleted</th><th></th>
</tr>';

foreach($st as $r):
?>
<tr>
<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="level-save">
<input type="hidden" name="return" value="<?=h($page)?>">
<input type="hidden" name="id" value="<?=h($r['level_id'])?>">

<td><?=h($r['level_id'])?></td>
<td><input name="name" value="<?=h($r['name'])?>" style="width:160px"></td>
<td><input name="stars" value="<?=h($r['stars'])?>" style="width:50px"></td>
<td><input name="difficulty" value="<?=h($r['difficulty'])?>" style="width:55px"></td>
<td><input type="checkbox" name="demon" <?=$r['demon']?'checked':''?>></td>
<td><input name="demon_difficulty" value="<?=h($r['demon_difficulty'])?>" style="width:55px"></td>
<td><input type="checkbox" name="featured" <?=$r['featured']?'checked':''?>></td>
<td><input name="epic" value="<?=h($r['epic'])?>" style="width:48px"></td>
<td><input name="requested_stars" value="<?=h($r['requested_stars'])?>" style="width:55px"></td>
<td><input name="downloads" value="<?=h($r['downloads'])?>" style="width:75px"></td>
<td><input name="likes" value="<?=h($r['likes'])?>" style="width:60px"></td>
<td><input type="checkbox" name="deleted" <?=$r['is_deleted']?'checked':''?>></td>
<td><button>Save</button></td>
</form>
</tr>
<?php endforeach ?>

</table></div>
<?php
}

/* =========================================================
   COMMENTS
========================================================= */

