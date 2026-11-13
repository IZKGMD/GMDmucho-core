<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Admin/AdminSessionGuard.php';

use MuchoCore\Admin\AdminSessionGuard;

$expect = static function (bool $value, string $label): void {
    if (!$value) {
        throw new RuntimeException('Admin role revocation: ' . $label);
    }
};

$storedHash = password_hash('fixture-password', PASSWORD_BCRYPT, ['cost' => 4]);
$fingerprint = hash('sha256', $storedHash);
$session = ['id' => 17, 'username' => 'fixture', 'role' => 'owner'];
$row = ['password_hash' => $storedHash, 'is_active' => 1, 'role' => 'owner'];

$expect(AdminSessionGuard::matches($session, $fingerprint, $row),
    'current owner session must work');
$expect(!AdminSessionGuard::matches($session, $fingerprint,
    array_replace($row, ['role' => 'viewer'])),
    'demotion from owner to viewer must revoke old session');
$expect(!AdminSessionGuard::matches($session, $fingerprint,
    array_replace($row, ['role' => 'admin'])),
    'owner-to-admin reassignment must revoke old session');
$expect(!AdminSessionGuard::matches($session, $fingerprint,
    array_replace($row, ['is_active' => 0])),
    'account suspension must revoke session');
$expect(!AdminSessionGuard::matches($session, $fingerprint,
    array_replace($row, ['password_hash' => password_hash('new password', PASSWORD_BCRYPT)])),
    'password rotation must revoke session');
$expect(!AdminSessionGuard::matches($session, '', $row),
    'missing fingerprint must not authenticate');
$expect(!AdminSessionGuard::matches($session, $fingerprint,
    array_replace($row, ['role' => ''])),
    'missing role must not be interpreted as owner');
$expect(AdminSessionGuard::matches(
    ['id' => 17, 'username' => 'fixture', 'role' => 'viewer'], $fingerprint,
    array_replace($row, ['role' => 'viewer'])),
    'new viewer login is allowed after downgrade');

// The live bootstrap must query the current role and invoke the guard.
$bootstrap = (string) file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');
$expect(str_contains($bootstrap, 'SELECT password_hash,is_active,role FROM admin_users'),
    'admin bootstrap must retrieve current role');
$expect(str_contains($bootstrap, 'AdminSessionGuard::matches('),
    'admin bootstrap must use role-aware session guard');

echo "MUCHOCORE_ADMIN_ROLE_SESSION_GUARD_OK\n";
