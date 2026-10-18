<?php
declare(strict_types=1);
require_once __DIR__.'/../core/AdminUi.php';
$state=adminListState($_GET);
$q=$state['q'];
$filter=in_array($state['filter'],['active','banned','inactive'],true) ? $state['filter'] : '';
$state['filter']=$filter;
$where=[];
$args=[];
if ($q!=='') {
    // Native PDO prepares require a distinct placeholder for each occurrence.
    $where[]='(a.username LIKE :username OR a.email LIKE :email OR a.account_id=:id)';
    $args=['username'=>'%'.$q.'%','email'=>'%'.$q.'%','id'=>ctype_digit($q)?(int)$q:0];
}
if ($filter==='banned') $where[]='a.is_banned=1';
if ($filter==='active') $where[]='a.is_active=1 AND a.is_banned=0';
if ($filter==='inactive') $where[]='a.is_active=0';
$offset=($state['p']-1)*25;
$sql='SELECT a.account_id,a.username,a.email,COALESCE(r.code,\'user\') AS role,
    a.is_active,a.is_banned,a.created_at,p.stars,p.moons,p.diamonds,p.secret_coins,
    p.user_coins,p.demons,p.creator_points FROM accounts a
    LEFT JOIN roles r ON r.id=a.role_id LEFT JOIN profiles p ON p.account_id=a.account_id';
if ($where) $sql.=' WHERE '.implode(' AND ',$where);
$st=$db->prepare($sql.' ORDER BY a.account_id DESC LIMIT 26 OFFSET '.$offset);
$st->execute($args);
$rows=$st->fetchAll(PDO::FETCH_ASSOC);
$hasMore=count($rows)>25;
$rows=array_slice($rows,0,25);
$manage=canPermission('players.manage');
$reset=canPermission('players.password_reset');
$delete=canPermission('players.delete');
$roleOptions=$manage ? $db->query("SELECT code FROM roles WHERE code IN ('user','moderator','elder_moderator','owner') ORDER BY priority DESC,id ASC")->fetchAll(PDO::FETCH_COLUMN) : [];
?>
<form class="search admin-list-search" method="get">
<input type="hidden" name="page" value="players">
<input type="search" name="q" value="<?=h($q)?>" placeholder="Username / email / Account ID" aria-label="Search players">
<select name="filter" aria-label="Status">
<?php foreach([''=>'All players','active'=>'Active','banned'=>'Banned','inactive'=>'Inactive'] as $value=>$label): ?>
<option value="<?=h($value)?>" <?=$filter===$value?'selected':''?>><?=h($label)?></option>
<?php endforeach ?></select><button>Search</button>
<?php if($q!=='' || $filter!==''): ?><a class="btn gray" href="/admin/?page=players">Reset</a><?php endif ?>
</form>
<?php adminListSummary($state,count($rows),$hasMore,'players'); ?>
<?php if(!$rows): ?><div class="card empty-state" role="status"><h2>No matching players</h2><p>Try another username, email, account ID, or status.</p><a class="btn gray" href="/admin/?page=players">Clear filters</a></div><?php endif ?>
<div class="admin-records">
<?php foreach($rows as $r): $id=(int)$r['account_id']; ?>
<details class="card admin-record" id="player-<?=$id?>">
<summary aria-label="Player <?=h($r['username'])?>, account #<?=$id?>"><?=adminGdIcon('players')?>
<span class="record-name"><b><?=h($r['username'])?></b><small>#<?=$id?> · <?=h($r['role'])?></small></span>
<span class="record-stat"><?=adminGdIcon('stars')?><?=number_format((int)($r['stars'] ?? 0))?></span>
<span class="badge <?=$r['is_banned']?'bad':($r['is_active']?'green':'')?>"><?=$r['is_banned']?'Banned':($r['is_active']?'Active':'Inactive')?></span>
<span class="record-open">Open</span></summary>
<div class="record-body">
<?php if($manage): ?>
<form method="post" class="admin-edit-grid">
<input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="account-save">
<input type="hidden" name="return" value="players"><input type="hidden" name="id" value="<?=$id?>">
<?php adminListReturnFields($state) ?>
<label>Username<input name="username" value="<?=h($r['username'])?>" maxlength="20" required></label>
<label>Email<input type="email" name="email" value="<?=h($r['email'])?>" maxlength="254" required></label>
<label>Role<select name="role"><?php foreach($roleOptions as $role): ?><option value="<?=h($role)?>" <?=$r['role']===$role?'selected':''?>><?=h($role)?></option><?php endforeach ?></select></label>
<div class="record-checks"><label><input type="checkbox" name="active" <?=$r['is_active']?'checked':''?>>Active</label><label><input type="checkbox" name="banned" <?=$r['is_banned']?'checked':''?>>Banned</label></div>
<button>Save changes</button></form>
<details class="record-extra"><summary>Statistics</summary>
<form method="post" class="admin-stats-grid">
<input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="profile-save">
<input type="hidden" name="return" value="players"><input type="hidden" name="id" value="<?=$id?>">
<?php adminListReturnFields($state) ?>
<?php foreach(['stars'=>'Stars','moons'=>'Moons','diamonds'=>'Diamonds','secret_coins'=>'Secret Coins','user_coins'=>'User Coins','demons'=>'Demons','creator_points'=>'Creator Points'] as $field=>$label): ?>
<label><span><?=adminGdIcon($field==='demons'?'hard-demon':$field)?><?=h($label)?></span><input type="number" min="0" max="2147483647" step="1" name="<?=h($field)?>" value="<?=h($r[$field] ?? 0)?>" required></label>
<?php endforeach ?><button>Save</button></form></details>
<?php else: ?><p><?=h($r['email'])?> · <?=h($r['created_at'])?></p><?php endif ?>
<?php if($reset): ?>
<details class="record-extra"><summary>Change password</summary>
<form method="post" class="admin-edit-grid" data-password-confirm>
<input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="password-reset">
<input type="hidden" name="return" value="players"><input type="hidden" name="id" value="<?=$id?>">
<?php adminListReturnFields($state) ?>
<label>New password<input type="password" name="new_password" minlength="8" autocomplete="new-password" required></label>
<label>Confirm password<input type="password" name="new_password_confirm" minlength="8" autocomplete="new-password" required></label>
<button>Change password</button></form></details>
<?php endif ?>
<?php if($delete): ?>
<details class="record-extra"><summary>Delete account permanently</summary>
<p class="admin-danger-note">This cannot be undone. The player account and its related data will be removed.</p>
<form method="post" data-confirm="Permanently delete this player account and all related data?">
<input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="account-delete">
<input type="hidden" name="return" value="players"><input type="hidden" name="id" value="<?=$id?>">
<?php adminListReturnFields($state) ?><button class="red">Delete account permanently</button></form></details>
<?php endif ?>
</div></details>
<?php endforeach ?></div>
<?php adminListPager('players',$state,$hasMore); ?>
