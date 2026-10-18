<?php
declare(strict_types=1);
require_once __DIR__.'/../core/AdminUi.php';
$state=adminListState($_GET);
$q=$state['q'];
$filter=in_array($state['filter'],['pending','rated','deleted'],true) ? $state['filter'] : '';
if ($page==='moderation') $filter='pending';
$state['filter']=$filter;
$where=[$filter==='deleted'?'l.is_deleted=1':'l.is_deleted=0'];
$args=[];
if ($filter==='pending') $where[]='l.requested_stars>0 AND l.stars=0';
if ($filter==='rated') $where[]='l.stars>0';
if ($q!=='') {
    $where[]='(l.name LIKE :name OR l.level_id=:id OR a.username LIKE :creator)';
    $args=['name'=>'%'.$q.'%','id'=>ctype_digit($q)?(int)$q:0,'creator'=>'%'.$q.'%'];
}
$offset=($state['p']-1)*25;
$st=$db->prepare('SELECT l.level_id,l.name,l.account_id,l.stars,l.difficulty,l.demon,
    l.demon_difficulty,l.auto_level,l.featured,l.epic,l.requested_stars,l.downloads,l.likes,l.is_deleted,
    COALESCE(a.username,\'—\') AS creator_name FROM levels l
    LEFT JOIN accounts a ON a.account_id=l.account_id WHERE '.implode(' AND ',$where).
    ' ORDER BY l.level_id DESC LIMIT 26 OFFSET '.$offset);
$st->execute($args);
$rows=$st->fetchAll(PDO::FETCH_ASSOC);
$hasMore=count($rows)>25;
$rows=array_slice($rows,0,25);
$manage=canPermission('levels.manage');
$rate=canPermission('levels.rate');
?>
<form class="search admin-list-search" method="get">
<input type="hidden" name="page" value="<?=h($page)?>">
<input type="search" name="q" value="<?=h($q)?>" placeholder="Level name / ID / creator" aria-label="Search levels">
<?php if($page!=='moderation'): ?><select name="filter" aria-label="Status">
<?php foreach([''=>'All levels','pending'=>'Requested Stars','rated'=>'Rated','deleted'=>'Deleted'] as $value=>$label): ?>
<option value="<?=h($value)?>" <?=$filter===$value?'selected':''?>><?=h($label)?></option>
<?php endforeach ?></select><?php endif ?>
<button>Search</button><a class="btn gray" href="/admin/?page=<?=h($page)?>">Reset</a></form>
<?php adminListSummary($state,count($rows),$hasMore,'levels'); ?>
<?php if(!$rows): ?><div class="card empty-state" role="status"><h2>No matching levels</h2><p>Try another level ID, title or creator, or reset filters.</p><a class="btn gray" href="/admin/?page=<?=h($page)?>">Clear filters</a></div><?php endif ?>
<div class="admin-records">
<?php foreach($rows as $r): $id=(int)$r['level_id']; $profile=adminDifficultyProfile($r); ?>
<details class="card admin-record" id="level-<?=$id?>">
<summary><?=adminGdIcon($profile,'gd-face')?>
<span class="record-name"><b><?=h($r['name'])?></b><small>#<?=$id?> · <?=h($r['creator_name'])?></small></span>
<span class="record-stat"><?=adminGdIcon('stars')?><?=(int)$r['stars']?></span>
<?php if($r['requested_stars']>0): ?><span class="badge"><?=h($r['requested_stars'])?> ★</span><?php endif ?>
<span class="record-open">Open</span></summary>
<div class="record-body">
<div class="record-metrics"><span><?=adminGdIcon('downloads')?><?=number_format((int)$r['downloads'])?></span><span><?=adminGdIcon('likes')?><?=number_format((int)$r['likes'])?></span><span><?=h(ucwords(str_replace('-',' ',$profile)))?></span></div>
<?php if($rate): ?><a class="btn" href="/admin/?page=rating&amp;id=<?=$id?>&amp;q=<?=rawurlencode($q)?>">Publish rating</a><?php endif ?>
<?php if($manage): ?>
<form method="post" class="admin-edit-grid record-extra">
<input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="level-details-save">
<input type="hidden" name="return" value="<?=h($page)?>"><input type="hidden" name="id" value="<?=$id?>">
<?php adminListReturnFields($state) ?>
<label>Name<input name="name" value="<?=h($r['name'])?>" maxlength="64" required></label>
<label class="record-checks"><input type="checkbox" name="deleted" <?=$r['is_deleted']?'checked':''?>>Deleted</label>
<button>Save changes</button></form>
<?php endif ?></div></details>
<?php endforeach ?></div>
<?php adminListPager($page,$state,$hasMore); ?>
