<?php

declare (strict_types=1);
$list = new \MuchoCore\Demonlist\DemonlistService($db);
$csrf = h(csrf());
$approvedLevel = max(0, (int) (filter_var($_GET['filter'] ?? '', FILTER_VALIDATE_INT) ?: 0));
$after = max(0, (int) (filter_var($_GET['p'] ?? '', FILTER_VALIDATE_INT) ?: 0));
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
    ?>
· <a href="?page=demonlist&amp;filter=<?php
    echo (int) $level['level_id'];
    ?>">Review records for level <?php
    echo (int) $level['level_id'];
    ?></a></p>
<?php
}
?>
</section>

<?php
foreach (['pending' => $list->pending(), 'approved' => $approved] as $status => $records) {
    $isPending = $status === 'pending';
    ?>
<section class="card" id="<?php
    echo $status;
    ?>-records">
<h2><?php
    echo $isPending ? 'Pending records' : 'Approved records';
    ?></h2>
<?php
    if (!$isPending) {
        ?>
<form method="get">
    <input type="hidden" name="page" value="demonlist">
    <label>Level ID <input name="filter" type="number" min="1" value="<?php
        echo $approvedLevel ?: '';
        ?>" required></label>
    <button>Find approved records</button>
</form>
<?php
    }
    if (!$records && ($isPending || $approvedLevel > 0)) {
        ?>
<p><?php
        echo $isPending ? 'No records awaiting review.' : 'No approved records on this page.';
        ?></p>
<?php
    }
    foreach ($records as $record) {
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
    <input type="hidden" name="revision" value="<?php
        echo (int) $record['revision'];
        ?>">
    <input type="hidden" name="return_filter" value="<?php
        echo $approvedLevel;
        ?>">
    <input type="hidden" name="return_p" value="<?php
        echo $after;
        ?>">
    <p><strong><?php
        echo h($record['username'] ?? 'Unknown');
        ?></strong> · <?php
        echo h($record['name'] ?? 'Unavailable level');
        ?>
    · <a href="https://www.youtube.com/watch?v=<?php
        echo h($record['video_id']);
        ?>">Watch 100% run</a></p>
    <label>Reason shown to the player (optional) <input name="decision_note" type="text" maxlength="280"></label>
    <?php
        if ($isPending) {
            ?>
    <button name="decision" value="approve">Approve</button>
    <button name="decision" value="reject">Reject</button>
    <?php
        } else {
            ?>
    <button name="decision" value="revoke">Revoke approval</button>
    <?php
        }
        ?>
</form>
<?php
    }
    if (!$isPending && $approvedLevel > 0) {
        if ($after > 0) {
            ?><a href="?page=demonlist&amp;filter=<?php
            echo $approvedLevel;
            ?>#approved-records">First page</a><?php
        }
        if ($hasMore) {
            ?><a href="?page=demonlist&amp;filter=<?php
            echo $approvedLevel;
            ?>&amp;p=<?php
            echo (int) end($records)['id'];
            ?>#approved-records">Next page</a><?php
        }
    }
    ?>
</section>
<?php
}
