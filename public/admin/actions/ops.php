<?php
declare(strict_types=1);

if (!in_array($action, [
    'ops-maintenance',
    'ops-registrations',
    'ops-retry-stale-jobs',
    'ops-resolve-alert',
], true)) {
    throw new RuntimeException('Invalid MuchoOps action.');
}

requirePermission('system.manage');

$rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 3);
$controlDir = defined('CONTROL_DIR')
    ? CONTROL_DIR
    : $rootDir . '/storage/control';

if (!is_dir($controlDir) && !@mkdir($controlDir, 0770, true) && !is_dir($controlDir)) {
    throw new RuntimeException('MuchoOps control directory is unavailable.');
}

/**
 * Atomically enable/disable a control flag.
 */
function muchoOpsSetFlag(string $controlDir, string $name, bool $enabled): void
{
    if (!preg_match('/^[a-z0-9.-]{1,96}$/', $name)) {
        throw new InvalidArgumentException('Invalid control flag.');
    }

    $path = rtrim($controlDir, '/\\') . DIRECTORY_SEPARATOR . $name;

    if (!$enabled) {
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Unable to disable ' . $name . '.');
        }
        return;
    }

    $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
    if (@file_put_contents($tmp, gmdate('c') . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to enable ' . $name . '.');
    }

    @chmod($tmp, 0660);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to activate ' . $name . '.');
    }

    @chmod($path, 0660);
}

try {
    if ($action === 'ops-maintenance') {
        $enabled = in_array(
            strtolower(trim((string)($_POST['enabled'] ?? ''))),
            ['1', 'true', 'on', 'yes'],
            true
        );

        muchoOpsSetFlag($controlDir, 'maintenance.flag', $enabled);

        audit(
            $db,
            'ops.maintenance',
            'maintenance.flag',
            ['enabled' => $enabled]
        );

        flash(
            $enabled
                ? 'Maintenance mode enabled. Game API traffic is now blocked.'
                : 'Maintenance mode disabled. Game API traffic is available again.'
        );

        header('Location:/admin/?page=ops');
        exit;
    }

    if ($action === 'ops-registrations') {
        $enabled = in_array(
            strtolower(trim((string)($_POST['enabled'] ?? ''))),
            ['1', 'true', 'on', 'yes'],
            true
        );

        muchoOpsSetFlag(
            $controlDir,
            'registrations-disabled.flag',
            $enabled
        );

        audit(
            $db,
            'ops.registrations',
            'registrations-disabled.flag',
            ['disabled' => $enabled]
        );

        flash(
            $enabled
                ? 'New account registrations are now disabled.'
                : 'New account registrations are now enabled.'
        );

        header('Location:/admin/?page=ops');
        exit;
    }

    if ($action === 'ops-retry-stale-jobs') {
        $minutes = (int)($_POST['minutes'] ?? 10);
        $minutes = max(1, min(1440, $minutes));

        $queue = new \MuchoCore\Job\JobQueue($db);
        $count = $queue->retryStale($minutes);

        audit(
            $db,
            'ops.jobs.retry_stale',
            'mucho_jobs',
            ['minutes' => $minutes, 'requeued' => $count]
        );

        flash(
            $count === 0
                ? 'No stale running jobs were found.'
                : $count . ' stale job' . ($count === 1 ? '' : 's') . ' requeued.'
        );

        header('Location:/admin/?page=ops');
        exit;
    }

    if ($action === 'ops-resolve-alert') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            throw new RuntimeException('Invalid alert ID.');
        }

        $stmt = $db->prepare(
            'UPDATE mucho_system_alerts
             SET resolved=1,
                 resolved_at=UTC_TIMESTAMP()
             WHERE id=:id AND resolved=0'
        );
        $stmt->execute(['id' => $id]);

        audit(
            $db,
            'ops.alert.resolve',
            'alert:' . $id,
            ['changed' => $stmt->rowCount() === 1]
        );

        flash(
            $stmt->rowCount() === 1
                ? 'Alert resolved.'
                : 'Alert was already resolved or no longer exists.'
        );

        header('Location:/admin/?page=ops');
        exit;
    }
} catch (Throwable $e) {
    $requestId = bin2hex(random_bytes(8));

    error_log(sprintf(
        '[MuchoCore MuchoOps] request=%s %s: %s | %s:%d',
        $requestId,
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    flash(
        'The MuchoOps operation could not be completed. Request ID: ' .
        $requestId,
        'error'
    );

    header('Location:/admin/?page=ops');
    exit;
}
