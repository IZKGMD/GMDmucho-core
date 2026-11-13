<?php
declare(strict_types=1);

/**
 * Same credential and session-expiry contract as the main /admin/ frontend.
 * Keep this check independent of the monitoring endpoint's SQL/UI behavior.
 *
 * @param array<string,mixed> $session
 * @param array<string,mixed> $admin
 */
function muchoV5SessionValid(array $session, array $admin, int $now): bool
{
    $identity = $session['admin'] ?? null;
    if (!is_array($identity) ||
        (int)($identity['id'] ?? 0) <= 0 ||
        (int)($identity['id'] ?? 0) !== (int)($admin['id'] ?? 0) ||
        (int)($admin['is_active'] ?? 0) !== 1) {
        return false;
    }

    $loginAt = (int)($session['admin_login_at'] ?? 0);
    $lastActivity = (int)($session['admin_last_activity'] ?? 0);
    if ($loginAt <= 0 || $lastActivity <= 0 ||
        $loginAt > $now || $lastActivity > $now ||
        $lastActivity < $loginAt ||
        $now - $loginAt > 86400 ||
        $now - $lastActivity > 1800) {
        return false;
    }

    $hash = (string)($admin['password_hash'] ?? '');
    $fingerprint = (string)($session['admin_password_fingerprint'] ?? '');
    return $hash !== '' && $fingerprint !== '' &&
        hash_equals(hash('sha256', $hash), $fingerprint);
}
