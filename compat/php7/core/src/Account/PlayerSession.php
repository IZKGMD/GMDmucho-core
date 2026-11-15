<?php

declare (strict_types=1);
namespace MuchoCore\Account;

use PDO;
/** Bind browser logins to current credentials and account eligibility. */
final class PlayerSession
{
    public static function create(array $account): array
    {
        return ['id' => (int) $account['account_id'], 'username' => (string) $account['username'], 'credential_version' => self::version($account)];
    }
    public static function resolve(PDO $db, $session)
    {
        if (!is_array($session) || !is_int($session['id'] ?? null) || $session['id'] <= 0 || !is_string($session['credential_version'] ?? null)) {
            return null;
        }
        $query = $db->prepare('SELECT account_id,username,password_hash,gjp2_hash,is_active,is_banned
            FROM accounts WHERE account_id=:id LIMIT 1');
        $query->execute(['id' => $session['id']]);
        $account = $query->fetch(PDO::FETCH_ASSOC);
        if (!$account || (int) $account['is_active'] !== 1 || (int) $account['is_banned'] !== 0 || !hash_equals(self::version($account), $session['credential_version'])) {
            return null;
        }
        return self::create($account);
    }
    private static function version(array $account): string
    {
        // Store a one-way fingerprint rather than reusable game credentials.
        return hash('sha256', (string) $account['password_hash'] . "\x00" . (string) ($account['gjp2_hash'] ?? ''));
    }
}
