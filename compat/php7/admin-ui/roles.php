<?php
use MuchoCore\Admin\AdminRbac;


requirePermission('roles.manage');

$roleRows=$db->query(
    'SELECT id,code,name,is_system
     FROM php7_admin_roles
     ORDER BY is_system DESC,name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$rolePermissionStmt=$db->prepare(
    'SELECT permission
     FROM php7_admin_role_permissions
     WHERE role_id=:id
     ORDER BY permission'
);

?>
<div class="card">
<h2 style="margin-top:0">Create custom role</h2>
<p class="muted">Create a role with exactly the permissions this role should have.</p>

<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="role-create">
<input type="hidden" name="return" value="roles">

<div style="display:grid;grid-template-columns:180px 1fr;gap:10px;max-width:720px">
<input name="code" maxlength="32" placeholder="level-moderator" pattern="[a-z][a-z0-9_-]{1,31}" required>
<input name="name" maxlength="64" placeholder="Level Moderator" required>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px;margin-top:15px">
<?php foreach(AdminRbac::permissions() as $permission=>$label): ?>
<label style="display:flex;gap:9px;align-items:flex-start;padding:10px;border:1px solid #273448;border-radius:10px;background:#0c1119;cursor:pointer">
<input type="checkbox" name="permissions[]" value="<?=h($permission)?>" style="margin-top:2px">
<span><b><?=h($label)?></b><small style="display:block;margin-top:2px;color:#6f7c91"><?=h($permission)?></small></span>
</label>
<?php endforeach; ?>
</div>

<button style="margin-top:14px">Create role</button>
</form>
</div>

<?php foreach($roleRows as $roleRow): ?>
<?php
$rolePermissionStmt->execute(['id'=>(int)$roleRow['id']]);
$rolePermissions=array_fill_keys(
    array_map('strval',$rolePermissionStmt->fetchAll(PDO::FETCH_COLUMN)),
    true
);
?>
<div class="card" style="margin-top:13px">
<div class="row" style="justify-content:space-between;align-items:center">
<div>
<h2 style="margin:0"><?=h($roleRow['name'])?></h2>
<div class="muted" style="margin-top:4px"><code><?=h($roleRow['code'])?></code> · <?=((int)$roleRow['is_system']===1?'Built-in':'Custom')?></div>
</div>
<?php if((int)$roleRow['is_system']===1): ?>
<span class="badge">System role</span>
<?php endif; ?>
</div>

<?php if((int)$roleRow['is_system']===1): ?>
<p class="muted" style="margin-bottom:0">Built-in roles keep the established MuchoCore permission model and cannot be modified from this page.</p>
<?php else: ?>
<form method="post" style="margin-top:13px">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="role-update">
<input type="hidden" name="return" value="roles">
<input type="hidden" name="id" value="<?=h((string)$roleRow['id'])?>">

<input name="name" maxlength="64" value="<?=h($roleRow['name'])?>" required style="width:min(520px,100%)">

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px;margin-top:13px">
<?php foreach(AdminRbac::permissions() as $permission=>$label): ?>
<label style="display:flex;gap:9px;align-items:flex-start;padding:10px;border:1px solid #273448;border-radius:10px;background:#0c1119;cursor:pointer">
<input type="checkbox" name="permissions[]" value="<?=h($permission)?>" <?=isset($rolePermissions[$permission])?'checked':''?> style="margin-top:2px">
<span><b><?=h($label)?></b><small style="display:block;margin-top:2px;color:#6f7c91"><?=h($permission)?></small></span>
</label>
<?php endforeach; ?>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
<button>Save permissions</button>
</div>
</form>

<form method="post" onsubmit="return confirm('Delete this custom role?')">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="role-delete">
<input type="hidden" name="return" value="roles">
<input type="hidden" name="id" value="<?=h((string)$roleRow['id'])?>">
<button class="gray" type="submit">Delete role</button>
</form>
<?php endif; ?>
</div>
<?php endforeach; ?>

<?php
