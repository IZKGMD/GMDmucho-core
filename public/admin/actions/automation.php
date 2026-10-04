<?php

declare(strict_types=1);

use MuchoCore\Job\AutomationScheduler;

if ($action !== 'automation-save') {
    throw new RuntimeException('Invalid Automation action.');
}

try {
    requirePermission('automation.manage');
    checkCsrf();

    $code = strtolower(trim((string)($_POST['code'] ?? '')));
    $enabled = isset($_POST['enabled']);
    $minutes = max(1, min(43200, (int)($_POST['interval_minutes'] ?? 60)));

    $scheduler = new AutomationScheduler($db);
    $scheduler->setSchedule(
        $code,
        $enabled,
        $minutes * 60
    );

    audit($db, 'automation.schedule.save', $code, [
        'enabled' => $enabled,
        'interval_minutes' => $minutes,
    ]);
    flash('Automation schedule updated.');
} catch (Throwable $e) {
    flash('Error: ' . $e->getMessage(), 'error');
}

$return = (string)($_POST['return'] ?? 'automation');
header('Location:/admin/?page=' . rawurlencode($return));
exit;
