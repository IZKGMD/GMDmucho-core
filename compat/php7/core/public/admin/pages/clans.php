<?php

declare (strict_types=1);
require_once \mucho7_compiled_include(\MUCHO7_PROJECT_ROOT . '/public/admin/pages' . '/../core/AdminUi.php');
use MuchoCore\Clan\ClanRepository;
if (!tableExists($db, 'mucho_clans')) {
    echo '<p>Clan tables are unavailable. Run the MuchoCore database migrations.</p>';
    return;
}
$state = adminListState($_GET);
$state['filter'] = in_array($state['filter'], ['open', 'closed'], true) ? $state['filter'] : '';
$repository = new ClanRepository($db);
$id = max(0, (int) ($_GET['id'] ?? 0));
$clan = $id ? $repository->getById($id) : null;
$fields = static function (string $action, int $clanId) use ($state) {
    echo '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
    echo '<input type="hidden" name="action" value="' . h($action) . '">';
    echo '<input type="hidden" name="return" value="clans"><input type="hidden" name="id" value="' . $clanId . '">';
    adminListReturnFields($state);
};
?>
<form method="get" class="admin-list-search">
<input type="hidden" name="page" value="clans">
<label>Search <input type="search" name="q" value="<?php 
echo h($state['q']);
?>" placeholder="Name, tag, owner or clan ID"></label>
<label>Joining <select name="filter">
<?php 
foreach (['' => 'All clans', 'open' => 'Open', 'closed' => 'Invite only'] as $value => $label) {
    ?>
<option value="<?php 
    echo h($value);
    ?>" <?php 
    echo $state['filter'] === $value ? 'selected' : '';
    ?>><?php 
    echo h($label);
    ?></option>
<?php 
}
?></select></label>
<button>Search</button> <a href="/admin/?page=clans">Reset</a>
</form>
<?php 
if ($id && !$clan) {
    ?><p>Clan not found.</p><?php 
}
if ($clan) {
    $members = $repository->members($id);
    ?>
<section class="card">
<h2>[<?php 
    echo h($clan['tag']);
    ?>] <?php 
    echo h($clan['name']);
    ?> #<?php 
    echo $id;
    ?></h2>
<p>Owner: <?php 
    echo h($clan['owner_username']);
    ?> (#<?php 
    echo (int) $clan['owner_account_id'];
    ?>). Members: <?php 
    echo (int) $clan['member_count'];
    ?>.</p>
<form method="post" class="admin-clan-form">
<?php 
    $fields('clan-save', $id);
    ?>
<p><label>Name <input name="name" value="<?php 
    echo h($clan['name']);
    ?>" minlength="2" maxlength="24" required></label></p>
<p><label>Tag <input name="tag" value="<?php 
    echo h($clan['tag']);
    ?>" pattern="[A-Za-z0-9]{2,6}" minlength="2" maxlength="6" required></label></p>
<p><label>Description <textarea name="description" maxlength="160" rows="3"><?php 
    echo h($clan['description']);
    ?></textarea></label></p>
<p><label>Member limit <input type="number" name="max_members" min="<?php 
    echo max(2, (int) $clan['member_count']);
    ?>" max="500" value="<?php 
    echo (int) $clan['max_members'];
    ?>" required></label></p>
<p><label><input type="checkbox" name="is_open" <?php 
    echo $clan['is_open'] ? 'checked' : '';
    ?>> Open joining</label></p>
<button>Save clan</button>
</form>
<h3>Members</h3>
<div class="table"><table data-no-filter>
<thead><tr><th scope="col">Account</th><th scope="col">Role</th><th scope="col">Actions</th></tr></thead>
<tbody>
<?php 
    foreach ($members as $member) {
        $accountId = (int) $member['account_id'];
        ?>
<tr><td><?php 
        echo h($member['username']);
        ?> #<?php 
        echo $accountId;
        ?></td><td><?php 
        echo h($member['role']);
        ?></td><td>
<?php 
        if ($member['role'] !== 'owner') {
            ?>
<form method="post"><?php 
            $fields('clan-role', $id);
            ?><input type="hidden" name="account_id" value="<?php 
            echo $accountId;
            ?>">
<label>Role for <?php 
            echo h($member['username']);
            ?> <select name="role"><?php 
            foreach (['member', 'officer'] as $role) {
                ?><option <?php 
                echo $member['role'] === $role ? 'selected' : '';
                ?>><?php 
                echo h($role);
                ?></option><?php 
            }
            ?></select></label>
<button>Save role</button></form>
<form method="post" data-confirm="Remove this member from the clan?"><?php 
            $fields('clan-kick', $id);
            ?><input type="hidden" name="account_id" value="<?php 
            echo $accountId;
            ?>"><button>Remove member</button></form>
<?php 
        } else {
            ?>Use Transfer ownership before removing the owner.<?php 
        }
        ?>
</td></tr>
<?php 
    }
    ?></tbody></table></div>
<details><summary>Transfer ownership</summary>
<form method="post" data-confirm="Transfer clan ownership to this member?">
<?php 
    $fields('clan-transfer', $id);
    ?>
<p><label>New owner <select name="account_id" required><option value="">Choose a member</option>
<?php 
    foreach ($members as $member) {
        if ($member['role'] === 'owner') {
            continue;
        }
        ?>
<option value="<?php 
        echo (int) $member['account_id'];
        ?>"><?php 
        echo h($member['username']);
        ?> #<?php 
        echo (int) $member['account_id'];
        ?></option>
<?php 
    }
    ?></select></label></p>
<p><label>Type <?php 
    echo h($clan['tag']);
    ?> to confirm <input name="confirm_tag" required autocomplete="off"></label></p>
<button>Transfer ownership</button></form></details>
<details><summary>Delete clan permanently</summary>
<p>Deletes the clan, memberships, invitations, bans and quests.</p>
<form method="post" data-confirm="Permanently delete this clan?">
<?php 
    $fields('clan-delete', $id);
    ?>
<label>Type <?php 
    echo h($clan['tag']);
    ?> to confirm <input name="confirm_tag" required autocomplete="off"></label>
<button>Delete clan permanently</button></form></details>
</section><hr>
<?php 
}
$where = [];
$args = [];
if ($state['q'] !== '') {
    $where[] = '(c.name LIKE :name OR c.tag LIKE :tag OR a.username LIKE :owner OR c.clan_id=:id)';
    $args = ['name' => '%' . $state['q'] . '%', 'tag' => '%' . $state['q'] . '%', 'owner' => '%' . $state['q'] . '%', 'id' => ctype_digit($state['q']) ? (int) $state['q'] : 0];
}
if ($state['filter'] !== '') {
    $where[] = 'c.is_open=' . ($state['filter'] === 'open' ? '1' : '0');
}
$stmt = $db->prepare('SELECT c.*,a.username AS owner_username,
    (SELECT COUNT(*) FROM mucho_clan_members m WHERE m.clan_id=c.clan_id) AS member_count
    FROM mucho_clans c JOIN accounts a ON a.account_id=c.owner_account_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.clan_id DESC LIMIT 26 OFFSET ' . ($state['p'] - 1) * 25);
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$hasMore = count($rows) > 25;
?>
<h2>Clans</h2>
<?php 
if (!$rows) {
    ?><p>No clans found.</p><?php 
} else {
    ?>
<div class="table"><table data-no-filter><thead><tr><th scope="col">ID</th><th scope="col">Clan</th><th scope="col">Owner</th><th scope="col">Members</th><th scope="col">Joining</th></tr></thead><tbody>
<?php 
    foreach (array_slice($rows, 0, 25) as $row) {
        ?>
<tr><td><?php 
        echo (int) $row['clan_id'];
        ?></td><td><a href="<?php 
        echo h(adminListUrl('clans', $state, $state['p']) . '&id=' . (int) $row['clan_id']);
        ?>">[<?php 
        echo h($row['tag']);
        ?>] <?php 
        echo h($row['name']);
        ?></a></td><td><?php 
        echo h($row['owner_username']);
        ?></td><td><?php 
        echo (int) $row['member_count'];
        ?> / <?php 
        echo (int) $row['max_members'];
        ?></td><td><?php 
        echo $row['is_open'] ? 'Open' : 'Invite only';
        ?></td></tr>
<?php 
    }
    ?></tbody></table></div>
<?php 
}
adminListPager('clans', $state, $hasMore);
