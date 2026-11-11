<?php
declare(strict_types=1);

/**
 * Read-only administrator readiness probe.
 * Never prints passwords, activation links, SQL errors or account names.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function muchoAdminReadinessState(int $admins, int $activeOwners, bool $sealed): string
{
    if ($admins === 0) {
        return $sealed ? 'sealed_no_owner' : 'pending';
    }

    return $activeOwners > 0 ? 'ready' : 'owner_missing';
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $db = (new \MuchoCore\Database\Database())->connection();
    $admins = (int)$db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    $owners = (int)$db->query(
        "SELECT COUNT(*) FROM admin_users WHERE role='owner' AND is_active=1"
    )->fetchColumn();
    $sealed = (bool)$db->query(
        'SELECT 1 FROM admin_initialization WHERE id=1 LIMIT 1'
    )->fetchColumn();
    echo 'MUCHO_ADMIN_STATE=' . muchoAdminReadinessState($admins, $owners, $sealed) . PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, "MUCHO_ADMIN_STATE=unavailable\n");
    exit(1);
}
