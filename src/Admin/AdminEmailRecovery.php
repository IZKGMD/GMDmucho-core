<?php
declare(strict_types=1);

namespace MuchoCore\Admin;

use MuchoCore\Security\RateLimiter;
use PDO;
use Throwable;

final class AdminEmailRecovery
{
    private const VERIFY_TTL = 1800;
    private const RESET_TTL = 1200;

    public static function ensureSchema(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS admin_email_recovery (
            admin_user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            verified_email VARCHAR(254) NULL UNIQUE,
            verified_at DATETIME NULL,
            pending_email VARCHAR(254) NULL,
            verify_token_hash CHAR(64) NULL,
            verify_expires_at DATETIME NULL,
            reset_token_hash CHAR(64) NULL,
            reset_expires_at DATETIME NULL,
            reset_sent_at DATETIME NULL,
            KEY idx_verify (verify_token_hash),
            KEY idx_reset (reset_token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function normalEmail(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (strlen($value) > 254 || strlen($value) < 5 ||
            preg_match('/[\r\n\x00-\x1F\x7F]/', $value) ||
            filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return $value;
    }

    public static function status(PDO $db, int $id): array
    {
        self::ensureSchema($db);
        $query = $db->prepare('SELECT verified_email,pending_email FROM admin_email_recovery WHERE admin_user_id=:id');
        $query->execute(['id' => $id]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: ['verified_email' => null, 'pending_email' => null];
    }

    public static function requestVerification(PDO $db, int $adminId, string $email, string $ip): bool
    {
        $email = self::normalEmail($email) ?? '';
        if ($email === '' || !AdminSmtp::configured()) {
            return false;
        }
        $limiter = new RateLimiter();
        if (!$limiter->allowStrict('admin-email-bind-ip|' . $ip, 10, 3600) ||
            !$limiter->allowStrict('admin-email-bind-user|' . $adminId, 3, 3600)) {
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $query = $db->prepare('INSERT INTO admin_email_recovery
            (admin_user_id,pending_email,verify_token_hash,verify_expires_at)
            VALUES (:id,:email,:hash,:expires)
            ON DUPLICATE KEY UPDATE pending_email=VALUES(pending_email),
            verify_token_hash=VALUES(verify_token_hash),verify_expires_at=VALUES(verify_expires_at)');
        $query->execute([
            'id' => $adminId, 'email' => $email, 'hash' => $hash,
            'expires' => gmdate('Y-m-d H:i:s', time() + self::VERIFY_TTL),
        ]);

        $link = AdminSmtp::origin() . '/admin/?email_verify=' . rawurlencode($token);
        $sent = AdminSmtp::send($email, 'MuchoCore admin email verification',
            "Confirm your administrator recovery email:\n\n" . $link .
            "\n\nExpires in 30 minutes. If you did not request this, ignore the message.");
        if (!$sent) {
            // Never leave a live verification token for a message that SMTP
            // did not accept. A concurrent newer request must not be cleared.
            $clear = $db->prepare('UPDATE admin_email_recovery SET
                pending_email=NULL,verify_token_hash=NULL,verify_expires_at=NULL
                WHERE admin_user_id=:id AND verify_token_hash=:hash');
            $clear->execute(['id' => $adminId, 'hash' => $hash]);
        }
        return $sent;
    }

    public static function confirmEmail(PDO $db, string $token): bool
    {
        if (!self::validToken($token)) {
            return false;
        }
        $hash = hash('sha256', $token);
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT r.admin_user_id FROM admin_email_recovery r
                JOIN admin_users a ON a.id=r.admin_user_id AND a.is_active=1
                WHERE r.verify_token_hash=:hash AND r.verify_expires_at>UTC_TIMESTAMP()
                AND r.pending_email IS NOT NULL LIMIT 1 FOR UPDATE');
            $query->execute(['hash' => $hash]);
            $id = $query->fetchColumn();
            if ($id === false) {
                $db->rollBack();
                return false;
            }

            $query = $db->prepare('UPDATE admin_email_recovery SET
                verified_email=pending_email,verified_at=UTC_TIMESTAMP(),
                pending_email=NULL,verify_token_hash=NULL,verify_expires_at=NULL,
                reset_token_hash=NULL,reset_expires_at=NULL
                WHERE admin_user_id=:id AND verify_token_hash=:hash');
            $query->execute(['id' => (int) $id, 'hash' => $hash]);
            if ($query->rowCount() !== 1) {
                $db->rollBack();
                return false;
            }
            self::audit($db, (int) $id, 'admin.email.verified');
            $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[MuchoCore Admin] Email verification could not complete.');
            return false;
        }
    }

    public static function requestReset(PDO $db, string $email, string $ip): void
    {
        $email = self::normalEmail($email);
        $limiter = new RateLimiter();
        if ($email === null || !AdminSmtp::configured() ||
            !$limiter->allowStrict('admin-reset-ip|' . $ip, 5, 900) ||
            !$limiter->allowStrict('admin-reset-email|' . hash('sha256', $email), 3, 900)) {
            return;
        }
        $query = $db->prepare('SELECT r.admin_user_id,r.reset_sent_at
            FROM admin_email_recovery r
            JOIN admin_users a ON a.id=r.admin_user_id
            WHERE r.verified_email=:email AND a.is_active=1 AND a.password_must_set=0
            LIMIT 1');
        $query->execute(['email' => $email]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || (!empty($row['reset_sent_at']) &&
            strtotime((string) $row['reset_sent_at'] . ' UTC') > time() - 600)) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $query = $db->prepare('UPDATE admin_email_recovery
            SET reset_token_hash=:hash,reset_expires_at=:expires,reset_sent_at=UTC_TIMESTAMP()
            WHERE admin_user_id=:id AND
                (reset_sent_at IS NULL OR reset_sent_at<UTC_TIMESTAMP()-INTERVAL 600 SECOND)');
        $query->execute([
            'hash' => hash('sha256', $token),
            'expires' => gmdate('Y-m-d H:i:s', time() + self::RESET_TTL),
            'id' => (int) $row['admin_user_id'],
        ]);
        if ($query->rowCount() !== 1) {
            return;
        }

        $link = AdminSmtp::origin() . '/admin/?email_reset=' . rawurlencode($token);
        $sent = AdminSmtp::send($email, 'MuchoCore admin password reset',
            "A password reset was requested for your MuchoCore administrator account.\n\n" .
            $link . "\n\nExpires in 20 minutes and can be used once.\n" .
            "Your authenticator requirement remains enabled. Ignore this if it was not you.");
        if (!$sent) {
            $clear = $db->prepare('UPDATE admin_email_recovery SET reset_token_hash=NULL,
                reset_expires_at=NULL,reset_sent_at=NULL
                WHERE admin_user_id=:id AND reset_token_hash=:hash');
            $clear->execute(['id' => (int) $row['admin_user_id'], 'hash' => hash('sha256', $token)]);
        }
    }

    public static function resetPassword(PDO $db, string $token, string $password, string $confirmation, string $ip): bool
    {
        if (!self::validToken($token) || strlen($password) < 12 || strlen($password) > 72 ||
            preg_match('/[\x00-\x1F\x7F]/', $password) ||
            !hash_equals($password, $confirmation) ||
            !(new RateLimiter())->allowStrict('admin-reset-apply-ip|' . $ip, 10, 900)) {
            return false;
        }
        $hash = hash('sha256', $token);
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT r.admin_user_id FROM admin_email_recovery r
                JOIN admin_users a ON a.id=r.admin_user_id AND a.is_active=1
                AND a.password_must_set=0
                WHERE r.reset_token_hash=:hash AND r.reset_expires_at>UTC_TIMESTAMP()
                AND r.verified_email IS NOT NULL LIMIT 1 FOR UPDATE');
            $query->execute(['hash' => $hash]);
            $id = $query->fetchColumn();
            if ($id === false) {
                $db->rollBack();
                return false;
            }
            $update = $db->prepare('UPDATE admin_users SET password_hash=:password WHERE id=:id AND is_active=1');
            $update->execute(['password' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int) $id]);
            if ($update->rowCount() !== 1) {
                $db->rollBack();
                return false;
            }
            $cleanup = $db->prepare('UPDATE admin_email_recovery SET
                reset_token_hash=NULL,reset_expires_at=NULL,reset_sent_at=NULL
                WHERE admin_user_id=:id AND reset_token_hash=:hash');
            $cleanup->execute(['id' => (int) $id, 'hash' => $hash]);
            if ($cleanup->rowCount() !== 1) {
                $db->rollBack();
                return false;
            }
            // Changing an administrator password must invalidate *all* API
            // sessions too, not only browser sessions bound to the old hash.
            // Keep revocation in the same transaction as the password update:
            // a failed revocation must never leave a successful reset behind.
            $revoke = $db->prepare('UPDATE mucho_admin_client_tokens
                SET revoked_at=UTC_TIMESTAMP()
                WHERE admin_user_id=:id AND revoked_at IS NULL');
            $revoke->execute(['id' => (int) $id]);

            self::audit($db, (int) $id, 'admin.password.email_reset');
            $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[MuchoCore Admin] Password reset transaction failed.');
            return false;
        }
    }

    private static function audit(PDO $db, int $id, string $action): void
    {
        $query = $db->prepare('INSERT INTO admin_audit_logs
            (admin_user_id,username,action,target,metadata,ip)
            SELECT id,username,:action,NULL,NULL,NULL FROM admin_users WHERE id=:id');
        $query->execute(['id' => $id, 'action' => $action]);
    }

    private static function validToken(string $token): bool
    {
        return strlen($token) === 64 && preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }
}
