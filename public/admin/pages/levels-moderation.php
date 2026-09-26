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

$sql=
'SELECT
 level_id,name,account_id,stars,difficulty,
 demon,demon_difficulty,featured,epic,
 requested_stars,downloads,likes,is_deleted,
 created_at
 FROM levels';

$args=[];

if ($page==='moderation') {
    $sql.=' WHERE requested_stars>0';
}
elseif ($q!=='') {
    $sql.=' WHERE name LIKE :q OR level_id=:id';

    $args=[
        'q'=>'%'.$q.'%',
        'id'=>ctype_digit($q)?(int)$q:0
    ];
}

$sql.=' ORDER BY level_id DESC LIMIT 150';

$st=$db->prepare($sql);
$st->execute($args);

if ($page==='levels') {
    echo '<form class="search">';
    echo '<input type="hidden" name="page" value="levels">';
    echo '<input name="q" value="'.h($q).'" placeholder="Level name / ID">';
    echo '<button>Search</button></form>';
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
<td>
<button type="submit" name="action" value="level-save">Save</button>
<button type="submit" name="action" value="level-delete" class="red" onclick="return confirm('Delete this level?')">Delete</button>
</td>
</form>
</tr>
<?php endforeach ?>

</table></div>
<?php
}

/* =========================================================
   COMMENTS
========================================================= */

