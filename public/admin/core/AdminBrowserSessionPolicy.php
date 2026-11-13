<?php

declare(strict_types=1);

namespace MuchoCore\Admin;

/**
 * Verify browser identity from current DB state on every request.
 * A changed role, username, password, active state or setup requirement
 * invalidates the session; no privilege is trusted from a stale cookie.
 */
final class AdminBrowserSessionPolicy
{
    public static function isCurrent(
        array $sessionIdentity,
        ?array $databaseIdentity,
        string $passwordFingerprint
    ): bool {
        if ($databaseIdentity === null ||
            (int)($databaseIdentity['is_active'] ?? 0) !== 1 ||
            (int)($databaseIdentity['password_must_set'] ?? 1) !== 0 ||
            $passwordFingerprint === '') {
            return false;
        }

        $id = (int)($databaseIdentity['id'] ?? 0);
        $role = (string)($databaseIdentity['role'] ?? '');
        $username = (string)($databaseIdentity['username'] ?? '');
        $passwordHash = (string)($databaseIdentity['password_hash'] ?? '');

        if ($id <= 0 || $role === '' || $username === '' || $passwordHash === '') {
            return false;
        }

        return
            (int)($sessionIdentity['id'] ?? 0) === $id &&
            hash_equals(hash('sha256', $passwordHash), $passwordFingerprint) &&
            hash_equals($role, (string)($sessionIdentity['role'] ?? '')) &&
            hash_equals($username, (string)($sessionIdentity['username'] ?? ''));
    }
}
