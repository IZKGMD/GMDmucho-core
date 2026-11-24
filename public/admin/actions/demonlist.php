<?php
declare(strict_types=1);

requirePermission('levels.rate');
$list = new \MuchoCore\Demonlist\DemonlistService($db);
$db->beginTransaction();
try {
    if ($action === 'demonlist-level') {
        $levelId = (int)(filter_var($_POST['level_id'] ?? '', FILTER_VALIDATE_INT) ?: 0);
        $position = (int)(filter_var($_POST['position'] ?? '', FILTER_VALIDATE_INT) ?: 0);
        $published = ($_POST['published'] ?? '') === '1';
        $list->saveLevel($levelId, $position, $published);
        audit($db, 'demonlist.level', (string)$levelId, ['position'=>$position, 'published'=>$published]);
        $notice = 'Demonlist updated.';
    } else {
        $recordId = (int)(filter_var($_POST['record_id'] ?? '', FILTER_VALIDATE_INT) ?: 0);
        $decision = $_POST['decision'] ?? '';
        if (!in_array($decision, ['approve', 'reject'], true)
            || !$list->review($recordId, (int)(admin()['id'] ?? admin()['account_id']), $decision === 'approve')) {
            throw new InvalidArgumentException('The record is unavailable or already reviewed.');
        }
        audit($db, 'demonlist.review', (string)$recordId, ['decision'=>$decision]);
        $notice = 'Record reviewed.';
    }
    $db->commit();
    flash($notice);
} catch (InvalidArgumentException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    flash($e->getMessage(), 'error');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}
header('Location: /admin/?page=demonlist', true, 303);
exit;
