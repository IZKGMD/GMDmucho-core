<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use MuchoCore\Admin\AdminEmailRecovery;

function recoveryAssert(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL admin email DB integration: ' . $description);
    }
    echo 'PASS ' . $description . PHP_EOL;
}

$host = getenv('TEST_DB_HOST') ?: '';
$port = (int) (getenv('TEST_DB_PORT') ?: '3306');
$password = getenv('TEST_DB_ROOT_PASSWORD') ?: '';
if ($host === '' || $password === '') {
    throw new RuntimeException('An isolated MariaDB test service is required.');
}
$database = 'muchocore_admin_mail_' . bin2hex(random_bytes(5));
$root = new PDO(
    'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
    'root', $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$root->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $db = new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
        'root', $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $db->exec("CREATE TABLE admin_users (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        totp_secret VARCHAR(64) NULL,
        password_must_set TINYINT NOT NULL DEFAULT 0,
        is_active TINYINT NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE admin_audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_user_id BIGINT UNSIGNED NULL,
        username VARCHAR(64) NOT NULL,
        action VARCHAR(128) NOT NULL,
        target VARCHAR(255) NULL,
        metadata TEXT NULL,
        ip VARCHAR(45) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $oldPassword = 'previous-admin-password-123';
    $admin = $db->prepare('INSERT INTO admin_users
        (username,password_hash,totp_secret,is_active) VALUES (:u,:h,:t,:a)');
    $admin->execute(['u' => 'admin', 'h' => password_hash($oldPassword, PASSWORD_DEFAULT),
        't' => 'TEST_TOTP_PRESERVED', 'a' => 1]);
    $id = (int) $db->lastInsertId();

    AdminEmailRecovery::ensureSchema($db);
    AdminEmailRecovery::ensureSchema($db);
    recoveryAssert((int) $db->query('SELECT COUNT(*) FROM admin_email_recovery')->fetchColumn() === 0,
        'schema is idempotent');

    $verifyToken = bin2hex(random_bytes(32));
    $q = $db->prepare('INSERT INTO admin_email_recovery
        (admin_user_id,pending_email,verify_token_hash,verify_expires_at)
        VALUES (:id,:email,:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))');
    $q->execute(['id' => $id, 'email' => 'admin@example.com',
        'hash' => hash('sha256', $verifyToken)]);
    recoveryAssert(AdminEmailRecovery::confirmEmail($db, $verifyToken),
        'first confirmation succeeds');
    recoveryAssert(!AdminEmailRecovery::confirmEmail($db, $verifyToken),
        'confirmation token is single-use');
    $row = AdminEmailRecovery::status($db, $id);
    recoveryAssert($row['verified_email'] === 'admin@example.com' &&
        $row['pending_email'] === null, 'address is verified and pending address cleared');

    $expired = bin2hex(random_bytes(32));
    $q = $db->prepare('UPDATE admin_email_recovery SET
        pending_email=:email,verify_token_hash=:hash,
        verify_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)
        WHERE admin_user_id=:id');
    $q->execute(['id' => $id, 'email' => 'changed@example.com',
        'hash' => hash('sha256', $expired)]);
    recoveryAssert(!AdminEmailRecovery::confirmEmail($db, $expired),
        'expired verification is rejected');
    recoveryAssert(AdminEmailRecovery::status($db, $id)['verified_email'] === 'admin@example.com',
        'unconfirmed replacement does not affect trusted recovery address');

    $resetToken = bin2hex(random_bytes(32));
    $db->prepare('UPDATE admin_email_recovery SET
        reset_token_hash=:hash,reset_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)
        WHERE admin_user_id=:id')->execute(['hash' => hash('sha256', $resetToken), 'id' => $id]);
    recoveryAssert(!AdminEmailRecovery::resetPassword($db, $resetToken, 'too-short', 'too-short', '127.0.0.1'),
        'weak password is rejected');
    recoveryAssert(!AdminEmailRecovery::resetPassword($db, $resetToken,
        'new-password-is-long-enough', 'mismatch', '127.0.0.1'),
        'confirmation mismatch is rejected');
    recoveryAssert(AdminEmailRecovery::resetPassword($db, $resetToken,
        'new-password-is-long-enough', 'new-password-is-long-enough', '127.0.0.1'),
        'valid password reset succeeds');
    recoveryAssert(!AdminEmailRecovery::resetPassword($db, $resetToken,
        'another-long-password', 'another-long-password', '127.0.0.1'),
        'reset link is single-use');

    $q = $db->prepare('SELECT password_hash,totp_secret FROM admin_users WHERE id=:id');
    $q->execute(['id' => $id]);
    $after = $q->fetch(PDO::FETCH_ASSOC);
    recoveryAssert(password_verify('new-password-is-long-enough', $after['password_hash']) &&
        !password_verify($oldPassword, $after['password_hash']),
        'password was changed and previous password rejected');
    recoveryAssert($after['totp_secret'] === 'TEST_TOTP_PRESERVED',
        'reset does not disable 2FA');
    recoveryAssert((int) $db->query(
        "SELECT COUNT(*) FROM admin_audit_logs WHERE action='admin.password.email_reset'"
    )->fetchColumn() === 1, 'reset action is audited without raw token');

    $expiredReset = bin2hex(random_bytes(32));
    $db->prepare('UPDATE admin_email_recovery SET
        reset_token_hash=:hash,reset_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)
        WHERE admin_user_id=:id')->execute(['hash' => hash('sha256', $expiredReset), 'id' => $id]);
    recoveryAssert(!AdminEmailRecovery::resetPassword($db, $expiredReset,
        'another-long-password', 'another-long-password', '127.0.0.1'),
        'expired reset link is rejected');
    $db->prepare('UPDATE admin_users SET is_active=0 WHERE id=:id')->execute(['id' => $id]);
    $disabledToken = bin2hex(random_bytes(32));
    $db->prepare('UPDATE admin_email_recovery SET
        reset_token_hash=:hash,reset_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)
        WHERE admin_user_id=:id')->execute(['hash' => hash('sha256', $disabledToken), 'id' => $id]);
    recoveryAssert(!AdminEmailRecovery::resetPassword($db, $disabledToken,
        'another-long-password', 'another-long-password', '127.0.0.1'),
        'disabled administrator cannot reset password');

    echo "ADMIN_EMAIL_RECOVERY_DB_PASS\n";
} finally {
    $root->exec('DROP DATABASE ' . $database);
}
