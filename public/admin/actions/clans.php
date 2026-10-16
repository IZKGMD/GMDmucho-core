<?php
declare(strict_types=1);

use MuchoCore\Admin\ClanAdminService;

requirePermission('social.manage');
try {
    ClanAdminService::apply($db, $action, $_POST, (string)admin()['username']);
    audit($db, $action, (string)(int)($_POST['id'] ?? 0), [
        'account_id'=>(int)($_POST['account_id'] ?? 0),
        'role'=>(string)($_POST['role'] ?? ''),
    ]);
    flash('Clan updated.');
} catch (PDOException $e) {
    if ((int)($e->errorInfo[1] ?? 0)!==1062) throw $e;
    flash('A clan with this name or tag already exists.', 'err');
} catch (InvalidArgumentException $e) {
    flash($e->getMessage(), 'err');
}
