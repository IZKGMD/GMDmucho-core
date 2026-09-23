<?php
declare(strict_types=1);

/*
 * MuchoCore Admin — Players
 * Extracted verbatim from legacy admin.
 * Copyright (C) 2026 IZK
 */

if (true) {

$q=trim((string)($_GET['q'] ?? ''));

echo '<form class="search">';
echo '<input type="hidden" name="page" value="players">';
echo '<input name="q" value="'.h($q).'" placeholder="Username / email / Account ID">';
echo '<button>Search</button></form>';

$sql=
'SELECT
 a.account_id,a.username,a.email,
 COALESCE(r.code,\'user\') AS role,
 a.is_active,a.is_banned,a.created_at,
 p.stars,p.moons,p.diamonds,p.secret_coins,
 p.user_coins,p.demons,p.creator_points
 FROM accounts a
 LEFT JOIN roles r ON r.id=a.role_id
 LEFT JOIN profiles p ON p.account_id=a.account_id';

$args=[];

if ($q!=='') {
    $sql.=
    ' WHERE a.username LIKE :q
       OR a.email LIKE :q
       OR a.account_id=:id';

    $args=[
        'q'=>'%'.$q.'%',
        'id'=>ctype_digit($q)?(int)$q:0
    ];
}

$sql.=' ORDER BY a.account_id DESC LIMIT 100';

$st=$db->prepare($sql);
$st->execute($args);

$rows=$st->fetchAll(PDO::FETCH_ASSOC);

$roleOptions=$db->query(
    'SELECT code FROM roles WHERE code IN (\'user\',\'moderator\',\'elder_moderator\',\'owner\') ORDER BY priority DESC, id ASC'
)->fetchAll(PDO::FETCH_COLUMN);

if(!$roleOptions){
    $roleOptions=['user'];
}

foreach($rows as $r) {
?>
<div class="card" style="margin:12px 0">

<div class="row">
<b>#<?=h($r['account_id'])?> <?=h($r['username'])?></b>
<span class="badge"><?=h($r['role'] === 'elder_moderator' ? 'Elder Moderator' : ucfirst((string)$r['role']))?></span>
<?php if($r['is_banned']): ?>
<span class="badge bad">BANNED</span>
<?php endif ?>
</div>

<form method="post" class="row" style="margin-top:12px">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="account-save">
<input type="hidden" name="return" value="players">
<input type="hidden" name="id" value="<?=h($r['account_id'])?>">

<input name="username" value="<?=h($r['username'])?>">
<input name="email" value="<?=h($r['email'])?>">

<select name="role">
<?php foreach($roleOptions as $x): ?>
<option value="<?=h($x)?>" <?=$r['role']===$x?'selected':''?>>
<?=h($x === 'elder_moderator' ? 'Elder Moderator' : ucfirst($x))?>
</option>
<?php endforeach ?>
</select>

<label>
<input type="checkbox" name="active" <?=$r['is_active']?'checked':''?>>
active
</label>

<label>
<input type="checkbox" name="banned" <?=$r['is_banned']?'checked':''?>>
ban
</label>

<button>Save</button>
</form>

<form method="post" class="row" style="margin-top:10px">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="profile-save">
<input type="hidden" name="return" value="players">
<input type="hidden" name="id" value="<?=h($r['account_id'])?>">

<?php
foreach([
'stars','moons','diamonds','secret_coins',
'user_coins','demons','creator_points'
] as $f):
?>
<label>
<small><?=h($f)?></small><br>
<input
 style="width:90px"
 name="<?=h($f)?>"
 value="<?=h($r[$f] ?? 0)?>"
>
</label>
<?php endforeach ?>

<button>Stats</button>
</form>

<form method="post" class="row" style="margin-top:10px">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="password-reset">
<input type="hidden" name="return" value="players">
<input type="hidden" name="id" value="<?=h($r['account_id'])?>">

<input
 type="password"
 name="new_password"
 placeholder="New password"
>

<button class="gray">Change password</button>
</form>

<?php if(rank(admin()['role'])>=40): ?>
<form
 method="post"
 style="margin-top:10px"
 onsubmit="return confirm('Permanently delete account <?=h($r['username'])?> and all related data?');"
>
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="account-delete">
<input type="hidden" name="return" value="players">
<input type="hidden" name="id" value="<?=h($r['account_id'])?>">

<button class="red">Delete account permanently</button>
</form>
<?php endif ?>

</div>
<?php
}
}

/* =========================================================
   LEVELS
========================================================= */

