<?php

declare (strict_types=1);
namespace MuchoCore\Account;

use PDO;
final class RecoveryRepository
{
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function findAccountByIdentity(string $identity)
    {
        $stmt = $this->pdo->prepare('SELECT
                account_id,
                username,
                email,
                is_active,
                is_banned
             FROM accounts
             WHERE username = :username_identity
                OR email = :email_identity
             LIMIT 1');
        $stmt->execute(['username_identity' => $identity, 'email_identity' => $identity]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
    public function createToken(int $accountId, string $tokenHash, int $ttlSeconds, string $ipHash)
    {
        // Bound housekeeping and keep unrelated accounts out of the transaction.
        $this->pdo->exec('DELETE FROM account_recovery_tokens
             WHERE expires_at <= NOW()
             ORDER BY expires_at LIMIT 100');
        $this->pdo->beginTransaction();
        try {
            if (!$this->lockActiveAccount($accountId)) {
                throw new \RuntimeException('Recovery account is unavailable.');
            }
            $cleanup = $this->pdo->prepare('DELETE FROM account_recovery_tokens WHERE account_id = :account_id');
            $cleanup->execute(['account_id' => $accountId]);
            $stmt = $this->pdo->prepare('INSERT INTO account_recovery_tokens
                (account_id, token_hash, expires_at, created_ip_hash)
             VALUES
                (:account_id, :token_hash, TIMESTAMPADD(SECOND, :ttl_seconds, NOW()), :created_ip_hash)');
            $stmt->execute(['account_id' => $accountId, 'token_hash' => $tokenHash, 'ttl_seconds' => $ttlSeconds, 'created_ip_hash' => $ipHash]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
    private function lockActiveAccount(int $accountId): bool
    {
        $stmt = $this->pdo->prepare('SELECT account_id FROM accounts
             WHERE account_id = :account_id AND is_active = 1 AND is_banned = 0
             FOR UPDATE');
        $stmt->execute(['account_id' => $accountId]);
        return $stmt->fetchColumn() !== false;
    }
    public function tokenIsValid(string $tokenHash): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1
             FROM account_recovery_tokens
             WHERE token_hash = :token_hash
               AND used_at IS NULL
               AND expires_at > NOW()
             LIMIT 1');
        $stmt->execute(['token_hash' => $tokenHash]);
        return $stmt->fetchColumn() !== false;
    }
    public function resetPassword(string $tokenHash, string $passwordHash, string $gjp2Hash)
    {
        // Read the token owner before locking anything. All password changes
        // lock the account before touching its recovery tokens.
        $lookup = $this->pdo->prepare('SELECT account_id FROM account_recovery_tokens
             WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW()');
        $lookup->execute(['token_hash' => $tokenHash]);
        $accountId = $lookup->fetchColumn();
        if ($accountId === false) {
            return null;
        }
        $accountId = (int) $accountId;
        $this->pdo->beginTransaction();
        try {
            if (!$this->lockActiveAccount($accountId)) {
                $this->pdo->rollBack();
                return null;
            }
            $stmt = $this->pdo->prepare('SELECT 1 FROM account_recovery_tokens
                 WHERE token_hash = :token_hash
                   AND account_id = :account_id
                   AND used_at IS NULL
                   AND expires_at > NOW()
                 FOR UPDATE');
            $stmt->execute(['token_hash' => $tokenHash, 'account_id' => $accountId]);
            if ($stmt->fetchColumn() === false) {
                $this->pdo->rollBack();
                return null;
            }
            $updateAccount = $this->pdo->prepare('UPDATE accounts SET
                    password_hash = :password_hash,
                    gjp2_hash = :gjp2_hash
                 WHERE account_id = :account_id
                 LIMIT 1');
            $updateAccount->execute(['account_id' => $accountId, 'password_hash' => $passwordHash, 'gjp2_hash' => $gjp2Hash]);
            if ($updateAccount->rowCount() !== 1) {
                throw new \RuntimeException('Unable to update password.');
            }
            // Password recovery revokes legacy 1.9 upload sessions so an
            // old authenticated device cannot keep using the previous session.
            $revokeSessions = $this->pdo->prepare('DELETE FROM mucho_legacy_19_sessions
                 WHERE account_id = :account_id');
            $revokeSessions->execute(['account_id' => $accountId]);
            $markUsed = $this->pdo->prepare('UPDATE account_recovery_tokens
                 SET used_at = NOW()
                 WHERE token_hash = :token_hash
                   AND used_at IS NULL
                   AND expires_at > NOW()');
            $markUsed->execute(['token_hash' => $tokenHash]);
            if ($markUsed->rowCount() !== 1) {
                throw new \RuntimeException('Unable to consume recovery token.');
            }
            $this->pdo->commit();
            return $accountId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
    public function audit(int $accountId, string $action, string $ip, array $metadata = [])
    {
        $stmt = $this->pdo->prepare('INSERT INTO audit_logs
                (account_id, action, ip_address, metadata)
             VALUES
                (:account_id, :action, :ip_address, :metadata)');
        $stmt->execute(['account_id' => $accountId, 'action' => $action, 'ip_address' => $ip, 'metadata' => \mucho7_json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
    private $pdo;
}
