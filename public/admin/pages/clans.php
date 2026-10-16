<?php
declare(strict_types=1);

require_once __DIR__.'/../core/AdminUi.php';

use MuchoCore\Clan\ClanRepository;

if (!tableExists($db, 'mucho_clans')) {
    echo '<p>Clan tables are unavailable. Run the MuchoCore database migrations.</p>';
    return;
}
$state=adminListState($_GET);
$state['filter']=in_array($state['filter'], ['open','closed'], true) ? $state['filter'] : '';
$repository=new ClanRepository($db);
$id=max(0,(int)($_GET['id'] ?? 0));
$clan=$id ? $repository->getById($id) : null;
$fields=static function(string $action, int $clanId) use($state): void {
    echo '<input type="hidden" name="csrf" value="'.h(csrf()).'">';
    echo '<input type="hidden" name="action" value="'.h($action).'">';
    echo '<input type="hidden" name="return" value="clans"><input type="hidden" name="id" value="'.$clanId.'">';
    adminListReturnFields($state);
};
?>
<form method="get">
<input type="hidden" name="page" value="clans">
<label>Search <input type="search" name="q" value="<?=h($state['q'])?>" placeholder="Name, tag, owner or clan ID"></label>
<label>Joining <select name="filter">
<?php foreach([''=>'All clans','open'=>'Open','closed'=>'Invite only'] as $value=>$label): ?>
<option value="<?=h($value)?>" <?=$state['filter']===$value?'selected':''?>><?=h($label)?></option>
<?php endforeach ?></select></label>
<button>Search</button> <a href="/admin/?page=clans">Reset</a>
</form>
<?php if($id && !$clan): ?><p>Clan not found.</p><?php endif ?>
<?php if($clan): $members=$repository->members($id); ?>
<section>
<h2>[<?=h($clan['tag'])?>] <?=h($clan['name'])?> #<?=$id?></h2>
<p>Owner: <?=h($clan['owner_username'])?> (#<?=(int)$clan['owner_account_id']?>). Members: <?=(int)$clan['member_count']?>.</p>
<form method="post">
<?php $fields('clan-save',$id) ?>
<p><label>Name <input name="name" value="<?=h($clan['name'])?>" minlength="2" maxlength="24" required></label></p>
<p><label>Tag <input name="tag" value="<?=h($clan['tag'])?>" pattern="[A-Za-z0-9]{2,6}" minlength="2" maxlength="6" required></label></p>
<p><label>Description <textarea name="description" maxlength="160" rows="3"><?=h($clan['description'])?></textarea></label></p>
<p><label>Member limit <input type="number" name="max_members" min="<?=max(2,(int)$clan['member_count'])?>" max="500" value="<?=(int)$clan['max_members']?>" required></label></p>
<p><label><input type="checkbox" name="is_open" <?=$clan['is_open']?'checked':''?>> Open joining</label></p>
<button>Save clan</button>
</form>
<h3>Members</h3>
<table>
<thead><tr><th scope="col">Account</th><th scope="col">Role</th><th scope="col">Actions</th></tr></thead>
<tbody>
<?php foreach($members as $member): $accountId=(int)$member['account_id']; ?>
<tr><td><?=h($member['username'])?> #<?=$accountId?></td><td><?=h($member['role'])?></td><td>
<?php if($member['role']!=='owner'): ?>
<form method="post"><?php $fields('clan-role',$id) ?><input type="hidden" name="account_id" value="<?=$accountId?>">
<label>Role for <?=h($member['username'])?> <select name="role"><?php foreach(['member','officer'] as $role): ?><option <?=$member['role']===$role?'selected':''?>><?=h($role)?></option><?php endforeach ?></select></label>
<button>Save role</button></form>
<form method="post" data-confirm="Remove this member from the clan?"><?php $fields('clan-kick',$id) ?><input type="hidden" name="account_id" value="<?=$accountId?>"><button>Remove member</button></form>
<?php else: ?>Use Transfer ownership before removing the owner.<?php endif ?>
</td></tr>
<?php endforeach ?></tbody></table>
<details><summary>Transfer ownership</summary>
<form method="post" data-confirm="Transfer clan ownership to this member?">
<?php $fields('clan-transfer',$id) ?>
<p><label>New owner <select name="account_id" required><option value="">Choose a member</option>
<?php foreach($members as $member): if($member['role']==='owner') continue; ?>
<option value="<?=(int)$member['account_id']?>"><?=h($member['username'])?> #<?=(int)$member['account_id']?></option>
<?php endforeach ?></select></label></p>
<p><label>Type <?=h($clan['tag'])?> to confirm <input name="confirm_tag" required autocomplete="off"></label></p>
<button>Transfer ownership</button></form></details>
<details><summary>Delete clan permanently</summary>
<p>Deletes the clan, memberships, invitations, bans and quests.</p>
<form method="post" data-confirm="Permanently delete this clan?">
<?php $fields('clan-delete',$id) ?>
<label>Type <?=h($clan['tag'])?> to confirm <input name="confirm_tag" required autocomplete="off"></label>
<button>Delete clan permanently</button></form></details>
</section><hr>
<?php endif ?>
<?php
$where=[];$args=[];
if($state['q']!=='') {
    $where[]='(c.name LIKE :name OR c.tag LIKE :tag OR a.username LIKE :owner OR c.clan_id=:id)';
    $args=['name'=>'%'.$state['q'].'%','tag'=>'%'.$state['q'].'%','owner'=>'%'.$state['q'].'%','id'=>ctype_digit($state['q'])?(int)$state['q']:0];
}
if($state['filter']!=='') $where[]='c.is_open='.($state['filter']==='open'?'1':'0');
$stmt=$db->prepare('SELECT c.*,a.username AS owner_username,
    (SELECT COUNT(*) FROM mucho_clan_members m WHERE m.clan_id=c.clan_id) AS member_count
    FROM mucho_clans c JOIN accounts a ON a.account_id=c.owner_account_id'.
    ($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY c.clan_id DESC LIMIT 26 OFFSET '.(($state['p']-1)*25));
$stmt->execute($args);
$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
$hasMore=count($rows)>25;
?>
<h2>Clans</h2>
<?php if(!$rows): ?><p>No clans found.</p><?php else: ?>
<table><thead><tr><th scope="col">ID</th><th scope="col">Clan</th><th scope="col">Owner</th><th scope="col">Members</th><th scope="col">Joining</th></tr></thead><tbody>
<?php foreach(array_slice($rows,0,25) as $row): ?>
<tr><td><?=(int)$row['clan_id']?></td><td><a href="<?=h(adminListUrl('clans',$state,$state['p']).'&id='.(int)$row['clan_id'])?>">[<?=h($row['tag'])?>] <?=h($row['name'])?></a></td><td><?=h($row['owner_username'])?></td><td><?=(int)$row['member_count']?> / <?=(int)$row['max_members']?></td><td><?=$row['is_open']?'Open':'Invite only'?></td></tr>
<?php endforeach ?></tbody></table>
<?php endif ?>
<?php adminListPager('clans',$state,$hasMore) ?>
