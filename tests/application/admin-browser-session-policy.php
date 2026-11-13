<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/public/admin/core/AdminBrowserSessionPolicy.php';

use MuchoCore\Admin\AdminBrowserSessionPolicy;

$password = password_hash('fixture-not-a-real-admin-password', PASSWORD_BCRYPT, ['cost' => 4]);
$session = ['id' => 41, 'role' => 'owner', 'username' => 'TestOwner'];
$row = [
    'id' => 41,
    'role' => 'owner',
    'username' => 'TestOwner',
    'password_hash' => $password,
    'is_active' => 1,
    'password_must_set' => 0,
];
$fingerprint = hash('sha256', $password);

$cases = [
    ['unchanged owner is current', $session, $row, $fingerprint, true],
    ['downgraded owner loses old browser session', $session,
        array_replace($row, ['role' => 'viewer']), $fingerprint, false],
    ['promoted role requires fresh login', $session,
        array_replace($row, ['role' => 'admin']), $fingerprint, false],
    ['renamed administrator requires new identity', $session,
        array_replace($row, ['username' => 'ChangedOwner']), $fingerprint, false],
    ['password reset invalidates browser session', $session,
        array_replace($row, ['password_hash' => password_hash('changed', PASSWORD_BCRYPT, ['cost' => 4])]),
        $fingerprint, false],
    ['suspended administrator is rejected', $session,
        array_replace($row, ['is_active' => 0]), $fingerprint, false],
    ['required initial password setup denies browser session', $session,
        array_replace($row, ['password_must_set' => 1]), $fingerprint, false],
    ['deleted administrator is rejected', $session, null, $fingerprint, false],
    ['different admin id does not reuse session', $session,
        array_replace($row, ['id' => 42]), $fingerprint, false],
    ['empty stored fingerprint is rejected', $session, $row, '', false],
    ['stale session role field is rejected', array_replace($session, ['role' => '']),
        $row, $fingerprint, false],
];
foreach ($cases as [$name, $identity, $database, $hash, $expected]) {
    if (AdminBrowserSessionPolicy::isCurrent($identity, $database, $hash) !== $expected) {
        throw new RuntimeException('Admin browser authorization regression: ' . $name);
    }
    echo 'PASS ' . $name . PHP_EOL;
}

$active = (string)file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.php');
if (!str_contains($active, 'AdminBrowserSessionPolicy::isCurrent(') ||
    !str_contains($active, 'SELECT id,password_hash,is_active,role,username,password_must_set') ||
    !str_contains($active, 'unset($_SESSION[\'admin\']')) {
    throw new RuntimeException('Active admin entrypoint must revalidate the current identity.');
}

echo "MUCHOCORE_ADMIN_BROWSER_ROLE_REVOCATION_OK\n";
