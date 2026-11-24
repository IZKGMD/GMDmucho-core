<?php

declare (strict_types=1);
$list = new \MuchoCore\Demonlist\DemonlistService($db);
$csrf = h(csrf());
?>
<section class="card">
<h2>Ranked levels</h2>
<p><a href="/demonlist.php">Open public Demonlist</a></p>
<form method="post">
    <input type="hidden" name="csrf" value="<?php
echo $csrf;
?>">
    <input type="hidden" name="action" value="demonlist-level">
    <input type="hidden" name="return" value="demonlist">
    <label>Level ID <input name="level_id" type="number" min="1" required></label>
    <label>Position <input name="position" type="number" min="1" max="500" required></label>
    <label><input type="checkbox" name="published" value="1" checked> Published</label>
    <button>Save level</button>
</form>
<?php
foreach ($list->levels(false) as $level) {
    ?>
<p><?php
    echo $level['is_published'] ? '#' . (int) $level['position'] : 'Hidden';
    ?> · <?php
    echo h($level['name']);
    ?> · ID <?php
    echo (int) $level['level_id'];
    ?></p>
<?php
}
?>
</section>

<section class="card">
<h2>Pending records</h2>
<?php
$pending = $list->pending();
if (!$pending) {
    ?><p>No records awaiting review.</p><?php
}
foreach ($pending as $record) {
    ?>
<form method="post">
    <input type="hidden" name="csrf" value="<?php
    echo $csrf;
    ?>">
    <input type="hidden" name="action" value="demonlist-review">
    <input type="hidden" name="return" value="demonlist">
    <input type="hidden" name="record_id" value="<?php
    echo (int) $record['id'];
    ?>">
    <p><strong><?php
    echo h($record['username'] ?? 'Unknown');
    ?></strong> · <?php
    echo h($record['name'] ?? 'Unavailable level');
    ?>
    · <a href="https://www.youtube.com/watch?v=<?php
    echo h($record['video_id']);
    ?>">Watch 100% run</a></p>
    <button name="decision" value="approve">Approve</button>
    <button name="decision" value="reject">Reject</button>
</form>
<?php
}
?>
</section>
