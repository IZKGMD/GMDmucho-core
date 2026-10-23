<?php use MuchoCore\Admin\AdminRbac; if(mucho7_admin_registry_ready($db) && canPermission('admins.manage')): ?>
<?php
$adminSetupInvite=$_SESSION['admin_setup_invite'] ?? null;
unset($_SESSION['admin_setup_invite']);
?>

<?php if(is_array($adminSetupInvite) && !empty($adminSetupInvite['url'])): ?>
<div class="card" style="margin-bottom:13px">
<h2>Administrator invitation</h2>
<p style="color:#9aa6b9;line-height:1.5">Give this one-time link to <b><?=h((string)($adminSetupInvite['username'] ?? 'administrator'))?></b>. They will create their own password. The link expires in 24 hours and is shown only once.</p>
<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
<input readonly value="<?=h((string)$adminSetupInvite['url'])?>" style="flex:1;min-width:220px">
<button type="button" data-copy-invite>Copy link</button>
</div>
</div>
<?php endif; ?>

<?php if(canPermission('admins.manage')): ?>

<div class="card">
<h2>Add administrator</h2>

<form method="post">

<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="admin-create">
<input type="hidden" name="return" value="admins">

<p><input name="username" placeholder="Username" autocomplete="off" required></p>
<p style="font-size:11px;color:#7f8ba0;line-height:1.5">The administrator will create their own password using a one-time setup link after you create the account.</p>

<select name="role">
<?php foreach(AdminRbac::roles($db) as $roleCode=>$roleName): ?>
<option value="<?=h($roleCode)?>"><?=h($roleName)?></option>
<?php endforeach; ?>
</select>

<button>Create</button>
</form>
</div>

<?php endif ?>

</div>
<?php

$admins=$db->query(
    'SELECT u.id,COALESCE(a.username,u.username) AS username,u.role,u.is_active,u.created_at,s.totp_secret
     FROM php7_admin_users u LEFT JOIN accounts a ON a.account_id=u.account_id LEFT JOIN php7_admin_security s ON s.account_id=u.id
     ORDER BY u.id'
)->fetchAll(PDO::FETCH_ASSOC);

echo '<div class="table"><table>';
echo '<tr><th>ID</th><th>User</th><th>Role</th><th>2FA</th><th>Active</th><th></th></tr>';

foreach($admins as $a) {
    echo '<tr>';
    echo '<td>'.h($a['id']).'</td>';
    echo '<td>'.h($a['username']).'</td>';
    echo '<td>'.h(AdminRbac::roleName($db,(string)$a['role'])).' <code style="font-size:10px;color:#6f7c91">'.h($a['role']).'</code></td>';
    echo '<td>'.(!empty($a['totp_secret'])?'YES':'NO').'</td>';
    echo '<td>'.h($a['is_active']).'</td>';

    echo '<td>';

    if(
        canPermission('admins.manage') &&
        (int)$a['id']!==(int)admin()['account_id']
    ) {
        echo '<form method="post"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="admin-role"><input type="hidden" name="id" value="'.h($a['id']).'"><select name="role" aria-label="Role for '.h($a['username']).'">';
        foreach(AdminRbac::roles($db) as $code=>$name)if(AdminRbac::canAssignRole($db,admin(),$code))echo '<option value="'.h($code).'"'.($code===$a['role']?' selected':'').'>'.h($name).'</option>';
        echo '</select><button>Save role</button></form>';
        echo '<form method="post">
        <input type="hidden" name="csrf" value="'.csrf().'">
        <input type="hidden" name="action" value="admin-toggle">
        <input type="hidden" name="return" value="admins">
        <input type="hidden" name="id" value="'.h($a['id']).'">
        <button class="gray">Toggle</button>
        </form>';
    }

    echo '</td></tr>';
}

echo '</table></div>';

endif; ?>
