<?php
declare(strict_types=1);

/**
 * Disposable database driver for the PHP 8 email-recovery HTTP/SMTP acceptance.
 * Runs only against GitHub Actions' isolated testgdps MariaDB tenant.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Admin\AdminEmailRecovery;
use MuchoCore\Database\Database;

if (getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('MUCHO_TEST_ALLOW_WRITES') !== '1' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    getenv('DB_PORT') !== '13307' ||
    getenv('DB_NAME') !== 'testgdps') {
    throw new RuntimeException('Refusing email-recovery acceptance outside isolated CI.');
}

$username = (string) getenv('MC_RECOVERY_TEST_USERNAME');
if (!preg_match('/^mc_mail_ci_[a-f0-9]{12}$/D', $username)) {
    throw new RuntimeException('Invalid disposable administrator test identity.');
}

$db = (new Database())->connection();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'testgdps') {
    throw new RuntimeException('Unexpected database. Refusing changes.');
}

$action = $argv[1] ?? '';
$lookup = $db->prepare('SELECT id FROM admin_users WHERE username=:username LIMIT 1');
$lookup->execute(['username' => $username]);
$id = (int) ($lookup->fetchColumn() ?: 0);

if ($action === 'create') {
    if ($id !== 0) {
        throw new RuntimeException('Test administrator already exists.');
    }
    $stmt = $db->prepare("INSERT INTO admin_users
        (username,password_hash,role,is_active,password_must_set)
        VALUES (:name,:hash,'admin',1,0)");
    $stmt->execute([
        'name' => $username,
        'hash' => password_hash((string) getenv('MC_RECOVERY_TEST_PASSWORD'), PASSWORD_DEFAULT),
    ]);
    $id = (int) $db->lastInsertId();
    AdminEmailRecovery::ensureSchema($db);
    echo "CREATED\n";
    exit;
}

if ($id < 1) {
    throw new RuntimeException('Disposable administrator is missing.');
}
AdminEmailRecovery::ensureSchema($db);

switch ($action) {
    case 'bind':
        $sent = AdminEmailRecovery::requestVerification(
            $db, $id, (string) getenv('MC_RECOVERY_TEST_EMAIL'), '192.0.2.81'
        );
        echo $sent ? "SENT\n" : "REJECTED\n";
        break;

    case 'request-reset':
        AdminEmailRecovery::requestReset(
            $db, (string) getenv('MC_RECOVERY_TEST_EMAIL'), '192.0.2.82'
        );
        echo "REQUESTED\n";
        break;

    case 'expire-verify':
        $stmt = $db->prepare('UPDATE admin_email_recovery
             SET verify_expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND
             WHERE admin_user_id=:id');
        $stmt->execute(['id' => $id]);
        echo "EXPIRED\n";
        break;

    case 'expire-reset':
        $stmt = $db->prepare('UPDATE admin_email_recovery
             SET reset_expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND
             WHERE admin_user_id=:id');
        $stmt->execute(['id' => $id]);
        echo "EXPIRED\n";
        break;

    case 'set-totp':
        $stmt = $db->prepare('UPDATE admin_users SET totp_secret=:secret WHERE id=:id');
        $stmt->execute(['id' => $id, 'secret' => 'JBSWY3DPEHPK3PXP']);
        echo "ENABLED\n";
        break;

    case 'require-password-setup':
    case 'complete-password-setup':
        $stmt = $db->prepare('UPDATE admin_users SET password_must_set=:required
            WHERE id=:id AND username=:name');
        $stmt->execute([
            'required' => $action === 'require-password-setup' ? 1 : 0,
            'id' => $id, 'name' => $username,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Disposable setup state update failed.');
        }
        echo "UPDATED\n";
        break;

    case 'state':
        $stmt = $db->prepare('SELECT a.password_hash,a.totp_secret,
            r.verified_email,r.pending_email,r.verify_token_hash,r.reset_token_hash,
            TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), r.verify_expires_at) AS verify_ttl,
            TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), r.reset_expires_at) AS reset_ttl,
            r.reset_sent_at
            FROM admin_users a LEFT JOIN admin_email_recovery r
              ON r.admin_user_id=a.id WHERE a.id=:id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Admin fixture disappeared.');
        }
        $row['password_initial_ok'] = password_verify(
            (string) getenv('MC_RECOVERY_TEST_PASSWORD'), (string) $row['password_hash']
        );
        $row['password_new_ok'] = password_verify(
            (string) getenv('MC_RECOVERY_TEST_NEW_PASSWORD'), (string) $row['password_hash']
        );
        unset($row['password_hash']);
        echo json_encode($row, JSON_THROW_ON_ERROR) . "\n";
        break;

    case 'cleanup':
        $db->beginTransaction();
        try {
            // API bearer sessions and audit/log attempts created by the
            // acceptance run must never remain in the shared test schema.
            $db->prepare('DELETE FROM mucho_admin_client_tokens WHERE admin_user_id=:id')
                ->execute(['id' => $id]);
            $db->prepare('DELETE FROM mucho_admin_client_audit WHERE admin_user_id=:id')
                ->execute(['id' => $id]);
            $db->prepare('DELETE FROM mucho_admin_client_login_attempts WHERE username=:name')
                ->execute(['name' => $username]);
            $stmt = $db->prepare('DELETE FROM admin_email_recovery WHERE admin_user_id=:id');
            $stmt->execute(['id' => $id]);
            $stmt = $db->prepare('DELETE FROM admin_audit_logs WHERE admin_user_id=:id');
            $stmt->execute(['id' => $id]);
            $stmt = $db->prepare('DELETE FROM admin_users WHERE id=:id AND username=:name');
            $stmt->execute(['id' => $id, 'name' => $username]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Test administrator cleanup failed.');
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        echo "CLEANED\n";
        break;

    default:
        throw new RuntimeException('Unknown disposable recovery test action.');
}
