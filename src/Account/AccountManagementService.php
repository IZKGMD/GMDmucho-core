<?php
declare(strict_types=1);

namespace MuchoCore\Account;

use MuchoCore\Security\RateLimitBackend;
use MuchoCore\Security\RateLimiter;
use PDO;
use Throwable;

/** Browser self-service requires the actual password, never a public account ID. */
final readonly class AccountManagementService
{
    private const DUMMY_HASH = '$2y$12$1/MaE58zhdgQZQpBkgNBhuqdaaih/yUIuXVGBWgthDWALLCBtilDC';

    public function __construct(
        private PDO $pdo,
        private RateLimitBackend $limiter = new RateLimiter()
    ) {}

    public function changePassword(string $username, string $current, string $password, string $confirmation, string $ip): bool
    {
        $username = trim($username);
        // Bcrypt uses at most 72 bytes. Do not accept passwords that silently
        // lose their suffix or contain a NUL, on either supported runtime.
        if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $username)
            || $current === '' || strlen($current) > 256 || strpos($current, "\0") !== false
            || strlen($password) < 8 || strlen($password) > 72 || strpos($password, "\0") !== false
            || !hash_equals($password, $confirmation)) {
            return false;
        }
        if (!$this->limiter->allowStrict('account-manage-ip|' . $ip, 10, 900)
            || !$this->limiter->allowStrict('account-manage-user|' . hash('sha256', strtolower($username)), 5, 900)) {
            return false;
        }

        // Do the expensive hash work before acquiring a row lock.
        $select = $this->pdo->prepare('SELECT account_id,password_hash,gjp2_hash,is_active,is_banned FROM accounts WHERE username=:username LIMIT 1');
        $select->execute(['username' => $username]);
        $account = $select->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            password_verify($current, self::DUMMY_HASH);
            return false;
        }
        $valid = password_verify($current, (string)$account['password_hash']);
        if (!$valid && (string)$account['gjp2_hash'] !== '') {
            $valid = password_verify(sha1($current . AccountService::GJP2_SALT), (string)$account['gjp2_hash'])
                || password_verify(sha1($current . AccountService::LEGACY_GJP2_SALT), (string)$account['gjp2_hash']);
        }
        if (!$valid || (int)$account['is_active'] !== 1 || (int)$account['is_banned'] !== 0) {
            return false;
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $gjp2Hash = password_hash(sha1($password . AccountService::GJP2_SALT), PASSWORD_DEFAULT);

        $this->pdo->beginTransaction();
        try {
            // A recovery or another password change may have happened while
            // hashing. Recheck both hashes and account eligibility under lock.
            $lock = $this->pdo->prepare('SELECT password_hash,gjp2_hash,is_active,is_banned FROM accounts WHERE account_id=? FOR UPDATE');
            $lock->execute([(int)$account['account_id']]);
            $latest = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$latest || (int)$latest['is_active'] !== 1 || (int)$latest['is_banned'] !== 0
                || !hash_equals((string)$account['password_hash'], (string)$latest['password_hash'])
                || !hash_equals((string)$account['gjp2_hash'], (string)$latest['gjp2_hash'])) {
                $this->pdo->rollBack();
                return false;
            }
            $id = (int)$account['account_id'];
            $this->pdo->prepare('UPDATE accounts SET password_hash=?,gjp2_hash=? WHERE account_id=?')->execute([$passwordHash, $gjp2Hash, $id]);
            $this->pdo->prepare('DELETE FROM mucho_legacy_19_sessions WHERE account_id=?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM account_recovery_tokens WHERE account_id=?')->execute([$id]);
            (new RecoveryRepository($this->pdo))->audit($id, 'account.password.changed', $ip);
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
