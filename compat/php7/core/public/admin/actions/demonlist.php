<?php

declare (strict_types=1);
requirePermission('levels.rate');
$list = new \MuchoCore\Demonlist\DemonlistService($db);
$db->beginTransaction();
try {
    if ($action === 'demonlist-level') {
        $levelId = (int) (filter_var($_POST['level_id'] ?? '', FILTER_VALIDATE_INT) ?: 0);
        $position = (int) (filter_var($_POST['position'] ?? '', FILTER_VALIDATE_INT) ?: 0);
        $published = ($_POST['published'] ?? '') === '1';
        $list->saveLevel($levelId, $position, $published);
        audit($db, 'demonlist.level', (string) $levelId, ['position' => $position, 'published' => $published]);
        $notice = 'Demonlist updated.';
    } else {
        $recordId = (int) (filter_var($_POST['record_id'] ?? '', FILTER_VALIDATE_INT) ?: 0);
        $revision = (int) (filter_var($_POST['revision'] ?? '', FILTER_VALIDATE_INT) ?: 0);
        $decision = is_string($_POST['decision'] ?? null) ? $_POST['decision'] : '';
        $note = is_string($_POST['decision_note'] ?? null) ? $_POST['decision_note'] : '';
        if (!$list->review($recordId, $revision, (int) (admin()['id'] ?? admin()['account_id']), $decision, $note)) {
            throw new InvalidArgumentException('The record has changed or is unavailable. Review the current submission.');
        }
        audit($db, 'demonlist.review', (string) $recordId, ['decision' => $decision, 'revision' => $revision]);
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
$filter = (int) (filter_var($_POST['return_filter'] ?? '', FILTER_VALIDATE_INT) ?: 0);
$after = (int) (filter_var($_POST['return_p'] ?? '', FILTER_VALIDATE_INT) ?: 0);
