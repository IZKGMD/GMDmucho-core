<?php

declare(strict_types=1);

/**
 * Database-backed web admin session revocation.
 *
 * Sessions are invalidated immediately when the corresponding admin account
 * is disabled or its session version changes.
 */
final class MuchoAdminSessionGuard
{
    public static function enforce(PDO $db): void
    {
        if (!isset($_SESSION['admin']) || !is_array($_SESSION['admin'])) {
            return;
        }

        $adminId = (int)($_SESSION['admin']['id'] ?? 0);
        $sessionVersion = (int)($_SESSION['admin_session_version'] ?? 0);

        if ($adminId <= 0 || $sessionVersion <= 0) {
            self::logout();
        }

        $query = $db->prepare(
            'SELECT is_active, session_version, role
             FROM admin_users
             WHERE id=:id
             LIMIT 1'
        );
        $query->execute(['id' => $adminId]);

        $row = $query->fetch(PDO::FETCH_ASSOC);

        if (
            !$row ||
            (int)$row['is_active'] !== 1 ||
            (int)$row['session_version'] !== $sessionVersion
        ) {
            self::logout();
        }

        $_SESSION['admin']['role'] = (string)$row['role'];
    }

    private static function logout(): never
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                (string)($params['path'] ?? '/'),
                (string)($params['domain'] ?? ''),
                (bool)($params['secure'] ?? false),
                (bool)($params['httponly'] ?? true)
            );
        }

        session_destroy();
        header('Location:/admin/');
        exit;
    }
}
