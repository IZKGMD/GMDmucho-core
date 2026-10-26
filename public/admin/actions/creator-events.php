<?php
declare(strict_types=1);
checkCsrf();
requireRank(40);
if ((admin()['role'] ?? '') !== 'owner') throw new RuntimeException('Only the owner can manage Creator Events.');
try {
    \MuchoCore\CreatorEvents\CreatorEventsAdmin::action($db,$_POST);
    audit($db,'creator-events.'.(string)($_POST['operation']??''));
    flash('Creator Events updated.');
} catch (Throwable $e) {
    flash($e instanceof PDOException?'Creator Events could not be updated.':$e->getMessage(),'error');
}
header('Location:/admin/?page=plugins');
exit;
