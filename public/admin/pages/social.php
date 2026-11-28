<?php
declare(strict_types=1);

$sections = [
    'friend_requests' => ['Friend requests', 'to_account_id', 'id DESC', 'Remove request'],
    'friends' => ['Friendships', 'friend_account_id', 'account_id DESC, friend_account_id DESC', 'Remove friendship'],
    'blocks' => ['Blocks', 'blocked_account_id', 'account_id DESC, blocked_account_id DESC', 'Remove block'],
];

foreach ($sections as $table => [$title, $peerColumn, $order, $actionLabel]):
    if (!tableExists($db, $table)) {
        continue;
    }
    $isRequest = $table === 'friend_requests';
    $requestColumns = $isRequest ? ', id, comment, is_read, created_at' : '';
    $rows = $db->query(
        "SELECT account_id, $peerColumn AS peer_id$requestColumns FROM $table ORDER BY $order LIMIT 120"
    )->fetchAll(PDO::FETCH_ASSOC);
?>
<section aria-labelledby="social-<?=h($table)?>">
<h2 id="social-<?=h($table)?>"><?=h($title)?></h2>
<?php if ($rows === []): ?>
<p class="empty-state">No <?=h(strtolower($title))?> found.</p>
<?php else: ?>
<div class="table"><table>
<thead><tr>
<th scope="col"><?php echo $isRequest ? 'Sender ID' : 'Account ID'; ?></th>
<th scope="col"><?php echo $isRequest ? 'Recipient ID' : ($table === 'friends' ? 'Friend ID' : 'Blocked account ID'); ?></th>
<?php if ($isRequest): ?>
<th scope="col">Request ID</th><th scope="col">Comment</th><th scope="col">Status</th><th scope="col">Created</th>
<?php endif ?>
<th scope="col">Action</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr>
<td><?=h($row['account_id'])?></td>
<td><?=h($row['peer_id'])?></td>
<?php if ($isRequest): ?>
<td><?=h($row['id'])?></td>
<td><?=h($row['comment'])?></td>
<td><?=match ((int)$row['is_read']) {0 => 'Unread', 1 => 'Read', default => 'Hidden'}?></td>
<td><?=h($row['created_at'])?></td>
<?php endif ?>
<td>
<form method="post" data-confirm="<?=h($actionLabel)?>?">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<input type="hidden" name="action" value="relation-delete">
<input type="hidden" name="return" value="social">
<input type="hidden" name="table" value="<?=h($table)?>">
<?php if ($isRequest): ?>
<input type="hidden" name="id" value="<?=h($row['id'])?>">
<?php else: ?>
<input type="hidden" name="account_id" value="<?=h($row['account_id'])?>">
<input type="hidden" name="peer_id" value="<?=h($row['peer_id'])?>">
<?php endif ?>
<button type="submit" class="red"><?=h($actionLabel)?></button>
</form>
</td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
</section>
<?php endforeach ?>
