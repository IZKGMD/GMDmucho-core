<?php
if(is_file($root.'/config/php7-complete.on')){


if (!tableExists($db,'messages')) {
    echo '<div class="card">';
    echo '<h2 style="margin-top:0">Messages</h2>';
    echo '<p class="muted">The messages feature is not enabled in the current database schema.</p>';
    echo '<p class="muted small">No <code>messages</code> table exists, so the admin panel will not crash here anymore.</p>';
    echo '</div>';
} else {

$rows=$db->query(
    'SELECT
        m.*,
        a.username sender,
        b.username receiver
     FROM messages m
     LEFT JOIN accounts a ON a.account_id=m.account_id
     LEFT JOIN accounts b ON b.account_id=m.to_account_id
     ORDER BY m.id DESC LIMIT 150'
)->fetchAll(PDO::FETCH_ASSOC);

echo '<div class="table"><table>';
echo '<tr><th>ID</th><th>From</th><th>To</th><th>Subject</th><th>Body</th><th>Read</th><th></th></tr>';

foreach($rows as $r):
?>
<tr>
<td><?=h($r['id'])?></td>
<td><?=h($r['sender'] ?? $r['account_id'])?></td>
<td><?=h($r['receiver'] ?? $r['to_account_id'])?></td>
<td><?=h($r['subject'] ?? '')?></td>
<td><?=h($r['body'] ?? '')?></td>
<td><?=h($r['is_read'] ?? 0)?></td>
<td>
<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="message-delete">
<input type="hidden" name="return" value="messages">
<input type="hidden" name="id" value="<?=h($r['id'])?>">
<button class="red">Delete</button>
</form>
</td>
</tr>
<?php endforeach ?>

</table></div>
<?php
}

}else{



if (!tableExists($db,'php7_messages')) {
    echo '<div class="card">';
    echo '<h2 style="margin-top:0">Messages</h2>';
    echo '<p class="muted">The messages feature is not enabled in the current database schema.</p>';
    echo '<p class="muted small">No <code>messages</code> table exists, so the admin panel will not crash here anymore.</p>';
    echo '</div>';
} else {

$rows=$db->query(
    'SELECT
        m.*,
        a.username sender,
        b.username receiver
     FROM php7_messages m
     LEFT JOIN accounts a ON a.account_id=m.account_id
     LEFT JOIN accounts b ON b.account_id=m.to_account_id
     ORDER BY m.id DESC LIMIT 150'
)->fetchAll(PDO::FETCH_ASSOC);

echo '<div class="table"><table>';
echo '<tr><th>ID</th><th>From</th><th>To</th><th>Subject</th><th>Body</th><th>Read</th><th></th></tr>';

foreach($rows as $r):
?>
<tr>
<td><?=h($r['id'])?></td>
<td><?=h($r['sender'] ?? $r['account_id'])?></td>
<td><?=h($r['receiver'] ?? $r['to_account_id'])?></td>
<td><?=h($r['subject'] ?? '')?></td>
<td><?=h($r['body'] ?? '')?></td>
<td><?=h($r['is_read'] ?? 0)?></td>
<td>
<form method="post">
<input type="hidden" name="csrf" value="<?=csrf()?>">
<input type="hidden" name="action" value="message-delete">
<input type="hidden" name="return" value="messages">
<input type="hidden" name="id" value="<?=h($r['id'])?>">
<button class="red">Delete</button>
</form>
</td>
</tr>
<?php endforeach ?>

</table></div>
<?php
}

}
