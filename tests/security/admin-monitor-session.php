<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/public/admin/core/MonitoringSession.php';

$check = static function (bool $ok, string $name): void {
    if (!$ok) {
        throw new RuntimeException('Monitoring session policy: ' . $name);
    }
    echo 'PASS ' . $name . PHP_EOL;
};

$now = 2000000000;
$passwordHash = password_hash('admin-monitor-secret', PASSWORD_BCRYPT, ['cost' => 4]);
$admin = [
    'id' => 7, 'username' => 'owner', 'role' => 'owner',
    'is_active' => 1, 'password_hash' => $passwordHash,
];
$session = [
    'admin' => ['id' => 7, 'username' => 'owner', 'role' => 'owner'],
    'admin_login_at' => $now - 60,
    'admin_last_activity' => $now - 10,
    'admin_password_fingerprint' => hash('sha256', $passwordHash),
];

$check(muchoV5SessionValid($session, $admin, $now),
    'valid fresh owner session retains monitoring access');

$mutate = static function (array $sessionChange = [], array $adminChange = [])
    use ($session, $admin, $now): bool {
    return muchoV5SessionValid(
        array_replace($session, $sessionChange),
        array_replace($admin, $adminChange),
        $now
    );
};

$check(!$mutate(['admin_password_fingerprint' => '']),
    'legacy session missing fingerprint rejected');
$check(!$mutate([], ['password_hash' => password_hash('rotated', PASSWORD_BCRYPT, ['cost' => 4])]),
    'password reset revokes previously issued monitor session');
$check(!$mutate(['admin_login_at' => $now - 86401]),
    'monitoring session expires at absolute 24-hour maximum');
$check(!$mutate(['admin_last_activity' => $now - 1801]),
    'monitoring session expires after 30 minutes idle');
$check(!$mutate(['admin_last_activity' => $now + 1]),
    'future activity timestamp cannot extend monitor access');
$check(!$mutate(['admin' => ['id' => 8]]),
    'session identity must match freshly loaded admin row');
$check(!$mutate([], ['is_active' => 0]),
    'deactivated administrator rejected');
$check(!$mutate([], ['id' => 8]),
    'changed administrator identity rejected');

$endpoint = file_get_contents(dirname(__DIR__, 2) . '/public/admin/v5-api.php');
$check(is_string($endpoint) &&
    str_contains($endpoint, "require_once __DIR__.'/core/MonitoringSession.php';") &&
    str_contains($endpoint, 'muchoV5SessionValid($_SESSION,$currentAdmin,time())') &&
    str_contains($endpoint, "'SELECT id,username,role,is_active,password_hash") &&
    substr_count($endpoint, '$currentAdminRank < 20') >= 2,
    'monitor endpoint enforces password revocation and moderation-only private telemetry');

echo "MUCHOCORE_ADMIN_MONITOR_SESSION_OK\n";
