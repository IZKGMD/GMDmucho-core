<?php

declare(strict_types=1);

namespace MuchoCore\Admin;

/**
 * Admin sessions are authorized against the current database identity.
 * A role downgrade must revoke an already-open owner/admin session even if
 * the password hash has not changed.
 */
final class AdminSessionGuard
{
    public static function matches(
        ?array $sessionAdmin,
        string $passwordFingerprint,
        ?array $databaseAdmin
    ): bool {
        if ($sessionAdmin === null || $databaseAdmin === null ||
            $passwordFingerprint === '') {
            return false;
        }

        $id = (int)($sessionAdmin['id'] ?? 0);
        $passwordHash = $databaseAdmin['password_hash'] ?? null;
        $sessionRole = $sessionAdmin['role'] ?? null;
        $currentRole = $databaseAdmin['role'] ?? null;

        if ($id <= 0 || (int)($databaseAdmin['is_active'] ?? 0) !== 1 ||
            !is_string($passwordHash) || $passwordHash === '' ||
            !is_string($sessionRole) || $sessionRole === '' ||
            !is_string($currentRole) || $currentRole === '') {
            return false;
        }

        return hash_equals(hash('sha256', $passwordHash), $passwordFingerprint)
            && hash_equals($currentRole, $sessionRole);
    }
}
