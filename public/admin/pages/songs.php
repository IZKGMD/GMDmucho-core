<?php
declare(strict_types=1);
require_once __DIR__.'/../core/AdminUi.php';
if (!tableExists($db,'songs')) {
    echo '<div class="card empty-state">Music is unavailable. Run database migrations.</div>';
    return;
}
$state=adminListState($_GET);
$filter=in_array($state['filter'],['pending','verified'],true)?$state['filter']:'';
$state['filter']=$filter;
$where=[];$args=[];
if ($state['q']!=='') {
    $where[]='(name LIKE :name OR author_name LIKE :author OR id=:id)';
    $args=['name'=>'%'.$state['q'].'%','author'=>'%'.$state['q'].'%','id'=>ctype_digit($state['q'])?(int)$state['q']:0];
}
if ($filter!=='') $where[]='is_verified='.($filter==='verified'?'1':'0');
$sql='SELECT id,name,author_name,size,is_verified FROM songs';
if ($where) $sql.=' WHERE '.implode(' AND ',$where);
$st=$db->prepare($sql.' ORDER BY id DESC LIMIT 26 OFFSET '.(($state['p']-1)*25));
$st->execute($args);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
$hasMore=count($rows)>25;$rows=array_slice($rows,0,25);
$manage=canPermission('music.manage');
?>
<form class="search admin-list-search" method="get">
<input type="hidden" name="page" value="songs">
<input type="search" name="q" value="<?=h($state['q'])?>" placeholder="Song / artist / ID" aria-label="Search music">
<select name="filter" aria-label="Status"><?php foreach([''=>'All music','pending'=>'Pending','verified'=>'Verified'] as $value=>$label): ?>
<option value="<?=h($value)?>" <?=$filter===$value?'selected':''?>><?=h($label)?></option><?php endforeach ?></select>
<button>Search</button><a class="btn gray" href="/admin/?page=songs">Reset</a></form>
<?php if($manage): ?>
<details class="card"><summary>Upload Music</summary>
<p class="muted">MP3 only, maximum 20 MB.</p>
<form method="post" enctype="multipart/form-data" class="admin-edit-grid">
<input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="music-upload"><input type="hidden" name="return" value="songs">
<?php adminListReturnFields($state) ?>
<label>Name<input name="title" maxlength="128" required></label><label>Author<input name="artist" maxlength="128" required></label>
<label>MP3<input type="file" name="music_file" accept=".mp3,audio/mpeg" required></label><button>Upload MP3</button></form></details>
<?php endif ?>
<?php if(!$rows): ?><div class="card empty-state">No music found. Change the search or filters.</div><?php else: ?>
<div class="table"><table data-no-filter><thead><tr><th>ID</th><th>Name</th><th>Author</th><th>Size</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr>
<td>#<?=(int)$r['id']?></td><td><?=h($r['name'])?></td><td><?=h($r['author_name'])?></td><td><?=h($r['size'])?> MB</td>
<td><span class="badge <?=$r['is_verified']?'green':''?>"><?=$r['is_verified']?'Verified':'Pending'?></span></td>
<td><?php if($manage): ?><div class="row">
<?php foreach([$r['is_verified']?'music-unverify':'music-verify'=>$r['is_verified']?'Hide':'Verify','music-delete'=>'Delete'] as $action=>$label): ?>
<form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="<?=h($action)?>"><input type="hidden" name="return" value="songs"><input type="hidden" name="id" value="<?=(int)$r['id']?>">
<?php adminListReturnFields($state) ?><button class="<?=$action==='music-delete'?'red':'gray'?>"><?=h($label)?></button></form>
<?php endforeach ?></div><?php endif ?></td></tr>
<?php endforeach ?></tbody></table></div>
<?php endif; adminListPager('songs',$state,$hasMore); ?>
