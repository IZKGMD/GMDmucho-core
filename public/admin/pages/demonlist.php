<?php
declare(strict_types=1);

$list = new \MuchoCore\Demonlist\DemonlistService($db);
$csrf = h(csrf());
$approvedLevel = max(0, (int)(filter_var($_GET['filter'] ?? '', FILTER_VALIDATE_INT) ?: 0));
$after = max(0, (int)(filter_var($_GET['p'] ?? '', FILTER_VALIDATE_INT) ?: 0));
$approved = $approvedLevel > 0 ? $list->approvedForReview($approvedLevel, $after) : [];
$hasMore = count($approved) > 50;
if ($hasMore) {
    array_pop($approved);
}
?>
<section class="card">
<h2>Ranked levels</h2>
<p><a href="/demonlist.php">Open public Demonlist</a></p>
<form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="demonlist-level">
    <input type="hidden" name="return" value="demonlist">
    <label>Level ID <input name="level_id" type="number" min="1" required></label>
    <label>Position <input name="position" type="number" min="1" max="500" required></label>
    <label><input type="checkbox" name="published" value="1" checked> Published</label>
    <button>Save level</button>
</form>
<?php foreach ($list->levels(false) as $level): ?>
<p><?= $level['is_published'] ? '#' . (int)$level['position'] : 'Hidden' ?> · <?= h($level['name']) ?>
· <a href="?page=demonlist&amp;filter=<?= (int)$level['level_id'] ?>">Review records for level <?= (int)$level['level_id'] ?></a></p>
<?php endforeach ?>
</section>

<?php foreach (['pending' => $list->pending(), 'approved' => $approved] as $status => $records): ?>
<?php $isPending = $status === 'pending'; ?>
<section class="card" id="<?= $status ?>-records">
<h2><?= $isPending ? 'Pending records' : 'Approved records' ?></h2>
<?php if (!$isPending): ?>
<form method="get">
    <input type="hidden" name="page" value="demonlist">
    <label>Level ID <input name="filter" type="number" min="1" value="<?= $approvedLevel ?: '' ?>" required></label>
    <button>Find approved records</button>
</form>
<?php endif ?>
<?php if (!$records && ($isPending || $approvedLevel > 0)): ?>
<p><?= $isPending ? 'No records awaiting review.' : 'No approved records on this page.' ?></p>
<?php endif ?>
<?php foreach ($records as $record): ?>
<form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="demonlist-review">
    <input type="hidden" name="return" value="demonlist">
    <input type="hidden" name="record_id" value="<?= (int)$record['id'] ?>">
    <input type="hidden" name="revision" value="<?= (int)$record['revision'] ?>">
    <input type="hidden" name="return_filter" value="<?= $approvedLevel ?>">
    <input type="hidden" name="return_p" value="<?= $after ?>">
    <p><strong><?= h($record['username'] ?? 'Unknown') ?></strong> · <?= h($record['name'] ?? 'Unavailable level') ?>
    · <a href="https://www.youtube.com/watch?v=<?= h($record['video_id']) ?>">Watch 100% run</a></p>
    <label>Reason shown to the player (optional) <input name="decision_note" type="text" maxlength="280"></label>
    <?php if ($isPending): ?>
    <button name="decision" value="approve">Approve</button>
    <button name="decision" value="reject">Reject</button>
    <?php else: ?>
    <button name="decision" value="revoke">Revoke approval</button>
    <?php endif ?>
</form>
<?php endforeach ?>
<?php if (!$isPending && $approvedLevel > 0): ?>
<?php if ($after > 0): ?><a href="?page=demonlist&amp;filter=<?= $approvedLevel ?>#approved-records">First page</a><?php endif ?>
<?php if ($hasMore): ?><a href="?page=demonlist&amp;filter=<?= $approvedLevel ?>&amp;p=<?= (int)end($records)['id'] ?>#approved-records">Next page</a><?php endif ?>
<?php endif ?>
</section>
<?php endforeach ?>
