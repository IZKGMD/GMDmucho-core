<?php

declare(strict_types=1);

namespace MuchoCore\Clan;

use PDO;
use RuntimeException;

final readonly class ClanRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(
        int $ownerAccountId,
        string $name,
        string $tag,
        string $description,
        bool $isOpen,
        int $maxMembers
    ): array {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO mucho_clans
                    (name, tag, description, owner_account_id, is_open, max_members)
                 VALUES
                    (:name, :tag, :description, :owner_account_id, :is_open, :max_members)'
            );
            $stmt->execute([
                'name'=>$name,
                'tag'=>$tag,
                'description'=>$description,
                'owner_account_id'=>$ownerAccountId,
                'is_open'=>$isOpen ? 1 : 0,
                'max_members'=>$maxMembers,
            ]);

            $clanId=(int)$this->pdo->lastInsertId();

            $member=$this->pdo->prepare(
                "INSERT INTO mucho_clan_members (clan_id, account_id, role)
                 VALUES (:clan_id, :account_id, 'owner')"
            );
            $member->execute([
                'clan_id'=>$clanId,
                'account_id'=>$ownerAccountId,
            ]);

            $this->pdo->commit();

            return $this->getById($clanId)
                ?? throw new RuntimeException('Created clan could not be loaded.');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function getById(int $clanId): ?array
    {
        $stmt=$this->pdo->prepare(
            'SELECT c.*, a.username AS owner_username,
                    (SELECT COUNT(*) FROM mucho_clan_members m WHERE m.clan_id=c.clan_id) AS member_count
             FROM mucho_clans c
             INNER JOIN accounts a ON a.account_id=c.owner_account_id
             WHERE c.clan_id=:clan_id
             LIMIT 1'
        );
        $stmt->execute(['clan_id'=>$clanId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function getForAccount(int $accountId): ?array
    {
        $stmt=$this->pdo->prepare(
            'SELECT c.*, m.role,
                    (SELECT COUNT(*) FROM mucho_clan_members mc WHERE mc.clan_id=c.clan_id) AS member_count
             FROM mucho_clan_members m
             INNER JOIN mucho_clans c ON c.clan_id=m.clan_id
             WHERE m.account_id=:account_id
             LIMIT 1'
        );
        $stmt->execute(['account_id'=>$accountId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function search(string $query, int $offset=0, int $limit=20): array
    {
        $stmt=$this->pdo->prepare(
            'SELECT c.*, a.username AS owner_username,
                    (SELECT COUNT(*) FROM mucho_clan_members m WHERE m.clan_id=c.clan_id) AS member_count
             FROM mucho_clans c
             INNER JOIN accounts a ON a.account_id=c.owner_account_id
             WHERE c.name LIKE :name OR c.tag LIKE :tag
             ORDER BY c.created_at DESC
             LIMIT '.(int)$limit.' OFFSET '.(int)$offset
        );
        $stmt->execute([
            'name'=>'%'.$query.'%',
            'tag'=>'%'.$query.'%',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function members(int $clanId): array
    {
        $stmt=$this->pdo->prepare(
            "SELECT m.account_id, m.role, m.joined_at,
                    a.username, p.user_id
             FROM mucho_clan_members m
             INNER JOIN accounts a ON a.account_id=m.account_id
             LEFT JOIN profiles p ON p.account_id=a.account_id
             WHERE m.clan_id=:clan_id
             ORDER BY CASE m.role
                    WHEN 'owner' THEN 0
                    WHEN 'officer' THEN 1
                    ELSE 2
                  END,
                  a.username ASC"
        );
        $stmt->execute(['clan_id'=>$clanId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function member(int $clanId, int $accountId): ?array
    {
        $stmt=$this->pdo->prepare(
            'SELECT m.*, a.username
             FROM mucho_clan_members m
             INNER JOIN accounts a ON a.account_id=m.account_id
             WHERE m.clan_id=:clan_id AND m.account_id=:account_id
             LIMIT 1'
        );
        $stmt->execute([
            'clan_id'=>$clanId,
            'account_id'=>$accountId,
        ]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function join(int $clanId, int $accountId): bool
    {
        $this->pdo->beginTransaction();

        try {
            $clanStmt=$this->pdo->prepare(
                'SELECT c.clan_id, c.is_open, c.max_members,
                        (SELECT COUNT(*)
                         FROM mucho_clan_members m
                         WHERE m.clan_id=c.clan_id) AS member_count
                 FROM mucho_clans c
                 WHERE c.clan_id=:clan_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $clanStmt->execute(['clan_id'=>$clanId]);
            $clan=$clanStmt->fetch(PDO::FETCH_ASSOC);

            if (!$clan) {
                throw new RuntimeException('Clan not found.');
            }

            if ((int)$clan['is_open'] !== 1) {
                throw new RuntimeException('Clan is not open for joining.');
            }

            if ($this->isBanned($clanId,$accountId)) {
                throw new RuntimeException('You are banned from this clan.');
            }

            if ((int)$clan['member_count'] >= (int)$clan['max_members']) {
                throw new RuntimeException('Clan is full.');
            }

            $existing=$this->pdo->prepare(
                'SELECT 1 FROM mucho_clan_members
                 WHERE account_id=:account_id
                 LIMIT 1'
            );
            $existing->execute(['account_id'=>$accountId]);

            if ($existing->fetchColumn() !== false) {
                throw new RuntimeException('Account is already in a clan.');
            }

            $stmt=$this->pdo->prepare(
                "INSERT INTO mucho_clan_members (clan_id, account_id, role)
                 VALUES (:clan_id, :account_id, 'member')"
            );
            $stmt->execute([
                'clan_id'=>$clanId,
                'account_id'=>$accountId,
            ]);

            $invite=$this->pdo->prepare(
                'DELETE FROM mucho_clan_invites
                 WHERE clan_id=:clan_id AND account_id=:account_id'
            );
            $invite->execute([
                'clan_id'=>$clanId,
                'account_id'=>$accountId,
            ]);

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function acceptInvite(int $inviteId, int $accountId): bool
    {
        $this->pdo->beginTransaction();

        try {
            $inviteStmt=$this->pdo->prepare(
                'SELECT i.invite_id, i.clan_id, i.account_id
                 FROM mucho_clan_invites i
                 WHERE i.invite_id=:invite_id
                   AND i.account_id=:account_id
                   AND i.expires_at>UTC_TIMESTAMP()
                 LIMIT 1
                 FOR UPDATE'
            );
            $inviteStmt->execute([
                'invite_id'=>$inviteId,
                'account_id'=>$accountId,
            ]);
            $invite=$inviteStmt->fetch(PDO::FETCH_ASSOC);

            if (!$invite) {
                throw new RuntimeException(
                    'Clan invitation not found or expired.'
                );
            }

            $clanStmt=$this->pdo->prepare(
                'SELECT c.clan_id, c.max_members,
                        (SELECT COUNT(*)
                         FROM mucho_clan_members m
                         WHERE m.clan_id=c.clan_id) AS member_count
                 FROM mucho_clans c
                 WHERE c.clan_id=:clan_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $clanStmt->execute(['clan_id'=>(int)$invite['clan_id']]);
            $clan=$clanStmt->fetch(PDO::FETCH_ASSOC);

            if (!$clan || (int)$clan['member_count'] >= (int)$clan['max_members']) {
                throw new RuntimeException('Clan is full or unavailable.');
            }

            if ($this->isBanned((int)$invite['clan_id'],$accountId)) {
                throw new RuntimeException('You are banned from this clan.');
            }

            $membership=$this->pdo->prepare(
                'SELECT 1 FROM mucho_clan_members
                 WHERE account_id=:account_id
                 LIMIT 1'
            );
            $membership->execute(['account_id'=>$accountId]);

            if ($membership->fetchColumn() !== false) {
                throw new RuntimeException('Account is already in a clan.');
            }

            $insert=$this->pdo->prepare(
                "INSERT INTO mucho_clan_members (clan_id, account_id, role)
                 VALUES (:clan_id, :account_id, 'member')"
            );
            $insert->execute([
                'clan_id'=>(int)$invite['clan_id'],
                'account_id'=>$accountId,
            ]);

            $delete=$this->pdo->prepare(
                'DELETE FROM mucho_clan_invites
                 WHERE invite_id=:invite_id AND account_id=:account_id'
            );
            $delete->execute([
                'invite_id'=>$inviteId,
                'account_id'=>$accountId,
            ]);

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function updateSettings(
        int $clanId,
        int $ownerAccountId,
        string $name,
        string $tag,
        string $description,
        bool $isOpen,
        int $maxMembers
    ): array {
        $this->pdo->beginTransaction();

        try {
            $clan=$this->lockedClan($clanId);

            if (!$clan) {
                throw new RuntimeException('Clan not found.');
            }

            if ((int)$clan['owner_account_id'] !== $ownerAccountId) {
                throw new RuntimeException('Clan owner permission required.');
            }

            if ((int)$clan['member_count'] > $maxMembers) {
                throw new RuntimeException('Maximum members cannot be below current membership.');
            }

            $stmt=$this->pdo->prepare(
                'UPDATE mucho_clans
                 SET name=:name,
                     tag=:tag,
                     description=:description,
                     is_open=:is_open,
                     max_members=:max_members
                 WHERE clan_id=:clan_id'
            );
            $stmt->execute([
                'name'=>$name,
                'tag'=>$tag,
                'description'=>$description,
                'is_open'=>$isOpen ? 1 : 0,
                'max_members'=>$maxMembers,
                'clan_id'=>$clanId,
            ]);

            $this->pdo->commit();

            return $this->getById($clanId)
                ?? throw new RuntimeException('Clan not found.');
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function transferOwnership(
        int $clanId,
        int $ownerAccountId,
        int $targetAccountId
    ): bool {
        $this->pdo->beginTransaction();

        try {
            $clan=$this->lockedClan($clanId);

            if (!$clan || (int)$clan['owner_account_id'] !== $ownerAccountId) {
                throw new RuntimeException('Clan owner permission required.');
            }

            if ($targetAccountId === $ownerAccountId) {
                throw new RuntimeException('Target is already the clan owner.');
            }

            $target=$this->member($clanId,$targetAccountId);

            if (!$target) {
                throw new RuntimeException('Target is not a member of the clan.');
            }

            $promote=$this->pdo->prepare(
                "UPDATE mucho_clan_members
                 SET role='owner'
                 WHERE clan_id=:clan_id AND account_id=:account_id"
            );
            $promote->execute([
                'clan_id'=>$clanId,
                'account_id'=>$targetAccountId,
            ]);

            $demote=$this->pdo->prepare(
                "UPDATE mucho_clan_members
                 SET role='officer'
                 WHERE clan_id=:clan_id AND account_id=:account_id"
            );
            $demote->execute([
                'clan_id'=>$clanId,
                'account_id'=>$ownerAccountId,
            ]);

            $owner=$this->pdo->prepare(
                'UPDATE mucho_clans
                 SET owner_account_id=:account_id
                 WHERE clan_id=:clan_id'
            );
            $owner->execute([
                'account_id'=>$targetAccountId,
                'clan_id'=>$clanId,
            ]);

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function disband(int $clanId, int $ownerAccountId): bool
    {
        $this->pdo->beginTransaction();

        try {
            $clan=$this->lockedClan($clanId);

            if (!$clan || (int)$clan['owner_account_id'] !== $ownerAccountId) {
                throw new RuntimeException('Clan owner permission required.');
            }

            $stmt=$this->pdo->prepare(
                'DELETE FROM mucho_clans WHERE clan_id=:clan_id'
            );
            $stmt->execute(['clan_id'=>$clanId]);

            $this->pdo->commit();
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function revokeInvite(int $inviteId, int $actorAccountId): bool
    {
        $this->pdo->beginTransaction();

        try {
            $stmt=$this->pdo->prepare(
                'SELECT i.clan_id
                 FROM mucho_clan_invites i
                 WHERE i.invite_id=:invite_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute(['invite_id'=>$inviteId]);
            $invite=$stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invite) {
                throw new RuntimeException('Clan invitation not found.');
            }

            $clan=$this->lockedClan((int)$invite['clan_id']);

            if (!$clan) {
                throw new RuntimeException('Clan not found.');
            }

            $actor=$this->member((int)$invite['clan_id'],$actorAccountId);

            if (!$actor || !in_array((string)$actor['role'],['owner','officer'],true)) {
                throw new RuntimeException('Clan officer permission required.');
            }

            $delete=$this->pdo->prepare(
                'DELETE FROM mucho_clan_invites
                 WHERE invite_id=:invite_id'
            );
            $delete->execute(['invite_id'=>$inviteId]);

            $this->pdo->commit();
            return $delete->rowCount() > 0;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function kick(int $clanId, int $actorAccountId, int $targetAccountId): bool
    {
        $this->pdo->beginTransaction();

        try {
            $clan=$this->lockedClan($clanId);
            $actor=$this->member($clanId,$actorAccountId);
            $target=$this->member($clanId,$targetAccountId);

            if (!$clan || !$actor || !$target || !in_array((string)$actor['role'],['owner','officer'],true)) {
                throw new RuntimeException('Clan officer permission required.');
            }

            if ((string)$target['role']==='owner') {
                throw new RuntimeException('The clan owner cannot be kicked.');
            }

            if ((string)$actor['role']==='officer' && (string)$target['role']!=='member') {
                throw new RuntimeException('Officers cannot remove other officers.');
            }

            $delete=$this->pdo->prepare(
                'DELETE FROM mucho_clan_members
                 WHERE clan_id=:clan_id AND account_id=:account_id'
            );
            $delete->execute([
                'clan_id'=>$clanId,
                'account_id'=>$targetAccountId,
            ]);

            $this->pdo->commit();
            return $delete->rowCount() > 0;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function setRole(
        int $clanId,
        int $ownerAccountId,
        int $targetAccountId,
        string $role
    ): bool {
        $this->pdo->beginTransaction();

        try {
            $clan=$this->lockedClan($clanId);

            if (!$clan || (int)$clan['owner_account_id'] !== $ownerAccountId) {
                throw new RuntimeException('Clan owner permission required.');
            }

            if (!in_array($role,['officer','member'],true)) {
                throw new RuntimeException('Invalid clan role.');
            }

            $target=$this->member($clanId,$targetAccountId);

            if (!$target || (string)$target['role']==='owner') {
                throw new RuntimeException('Target is not eligible for role changes.');
            }

            $stmt=$this->pdo->prepare(
                'UPDATE mucho_clan_members
                 SET role=:role
                 WHERE clan_id=:clan_id AND account_id=:account_id'
            );
            $stmt->execute([
                'role'=>$role,
                'clan_id'=>$clanId,
                'account_id'=>$targetAccountId,
            ]);

            $this->pdo->commit();
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function ban(
        int $clanId,
        int $actorAccountId,
        int $targetAccountId,
        string $reason=''
    ): bool {
        $this->pdo->beginTransaction();

        try {
            $clan=$this->lockedClan($clanId);
            $actor=$this->member($clanId,$actorAccountId);

            if (!$clan || !$actor || !in_array((string)$actor['role'],['owner','officer'],true)) {
                throw new RuntimeException('Clan officer permission required.');
            }

            if ($targetAccountId<=0 || $targetAccountId===$actorAccountId) {
                throw new RuntimeException('Invalid clan ban target.');
            }

            $account=$this->pdo->prepare(
                'SELECT 1 FROM accounts
                 WHERE account_id=:account_id
                 LIMIT 1'
            );
            $account->execute(['account_id'=>$targetAccountId]);

            if ($account->fetchColumn() === false) {
                throw new RuntimeException('Target account not found.');
            }

            $target=$this->member($clanId,$targetAccountId);

            if ($target) {
                if ((string)$target['role']==='owner') {
                    throw new RuntimeException('The clan owner cannot be banned.');
                }

                if (
                    (string)$actor['role']==='officer' &&
                    (string)$target['role']!=='member'
                ) {
                    throw new RuntimeException('Officers cannot ban other officers.');
                }

                $delete=$this->pdo->prepare(
                    'DELETE FROM mucho_clan_members
                     WHERE clan_id=:clan_id AND account_id=:account_id'
                );
                $delete->execute([
                    'clan_id'=>$clanId,
                    'account_id'=>$targetAccountId,
                ]);
            }

            $invite=$this->pdo->prepare(
                'DELETE FROM mucho_clan_invites
                 WHERE clan_id=:clan_id AND account_id=:account_id'
            );
            $invite->execute([
                'clan_id'=>$clanId,
                'account_id'=>$targetAccountId,
            ]);

            $ban=$this->pdo->prepare(
                "INSERT INTO mucho_clan_bans
                    (clan_id, account_id, banned_by_account_id, reason)
                 VALUES
                    (:clan_id, :account_id, :banned_by, :reason)
                 ON DUPLICATE KEY UPDATE
                    banned_by_account_id=VALUES(banned_by_account_id),
                    reason=VALUES(reason),
                    created_at=CURRENT_TIMESTAMP,
                    expires_at=NULL"
            );
            $ban->execute([
                'clan_id'=>$clanId,
                'account_id'=>$targetAccountId,
                'banned_by'=>$actorAccountId,
                'reason'=>$reason,
            ]);

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function unban(int $clanId, int $actorAccountId, int $targetAccountId): bool
    {
        $this->pdo->beginTransaction();

        try {
            $clan=$this->lockedClan($clanId);
            $actor=$this->member($clanId,$actorAccountId);

            if (!$clan || !$actor || !in_array((string)$actor['role'],['owner','officer'],true)) {
                throw new RuntimeException('Clan officer permission required.');
            }

            $stmt=$this->pdo->prepare(
                'DELETE FROM mucho_clan_bans
                 WHERE clan_id=:clan_id AND account_id=:account_id'
            );
            $stmt->execute([
                'clan_id'=>$clanId,
                'account_id'=>$targetAccountId,
            ]);

            $this->pdo->commit();
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function bans(int $clanId): array
    {
        $stmt=$this->pdo->prepare(
            'SELECT b.ban_id, b.account_id, b.banned_by_account_id,
                    b.reason, b.created_at, b.expires_at,
                    a.username,
                    ab.username AS banned_by_username
             FROM mucho_clan_bans b
             INNER JOIN accounts a ON a.account_id=b.account_id
             INNER JOIN accounts ab ON ab.account_id=b.banned_by_account_id
             WHERE b.clan_id=:clan_id
               AND (b.expires_at IS NULL OR b.expires_at>UTC_TIMESTAMP())
             ORDER BY b.created_at DESC'
        );
        $stmt->execute(['clan_id'=>$clanId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isBanned(int $clanId, int $accountId): bool
    {
        $stmt=$this->pdo->prepare(
            'SELECT 1
             FROM mucho_clan_bans
             WHERE clan_id=:clan_id
               AND account_id=:account_id
               AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())
             LIMIT 1'
        );
        $stmt->execute([
            'clan_id'=>$clanId,
            'account_id'=>$accountId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function lockedClan(int $clanId): ?array
    {
        $stmt=$this->pdo->prepare(
            'SELECT c.*,
                    (SELECT COUNT(*)
                     FROM mucho_clan_members m
                     WHERE m.clan_id=c.clan_id) AS member_count
             FROM mucho_clans c
             WHERE c.clan_id=:clan_id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['clan_id'=>$clanId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function removeMember(int $clanId, int $accountId): bool
    {
        $stmt=$this->pdo->prepare(
            'DELETE FROM mucho_clan_members
             WHERE clan_id=:clan_id AND account_id=:account_id'
        );
        $stmt->execute([
            'clan_id'=>$clanId,
            'account_id'=>$accountId,
        ]);

        return $stmt->rowCount()>0;
    }


    public function invite(int $clanId, int $accountId, int $invitedBy): bool
    {
        if ($this->isBanned($clanId,$accountId)) {
            throw new RuntimeException('Target account is banned from this clan.');
        }

        $stmt=$this->pdo->prepare(
            "INSERT INTO mucho_clan_invites
                (clan_id, account_id, invited_by_account_id, expires_at)
             VALUES
                (:clan_id, :account_id, :invited_by, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY))
             ON DUPLICATE KEY UPDATE
                invited_by_account_id=VALUES(invited_by_account_id),
                created_at=CURRENT_TIMESTAMP,
                expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
        );

        return $stmt->execute([
            'clan_id'=>$clanId,
            'account_id'=>$accountId,
            'invited_by'=>$invitedBy,
        ]);
    }

    public function getInvite(int $inviteId, int $accountId): ?array
    {
        $stmt=$this->pdo->prepare(
            'SELECT i.*, c.name, c.tag
             FROM mucho_clan_invites i
             INNER JOIN mucho_clans c ON c.clan_id=i.clan_id
             WHERE i.invite_id=:invite_id
               AND i.account_id=:account_id
               AND i.expires_at>UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([
            'invite_id'=>$inviteId,
            'account_id'=>$accountId,
        ]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function deleteInvite(int $inviteId, int $accountId): bool
    {
        $stmt=$this->pdo->prepare(
            'DELETE FROM mucho_clan_invites
             WHERE invite_id=:invite_id AND account_id=:account_id'
        );
        $stmt->execute([
            'invite_id'=>$inviteId,
            'account_id'=>$accountId,
        ]);

        return $stmt->rowCount()>0;
    }

    public function invitations(int $accountId): array
    {
        $stmt=$this->pdo->prepare(
            'SELECT i.invite_id, i.clan_id, i.created_at, i.expires_at,
                    c.name, c.tag, a.username AS invited_by_username
             FROM mucho_clan_invites i
             INNER JOIN mucho_clans c ON c.clan_id=i.clan_id
             INNER JOIN accounts a ON a.account_id=i.invited_by_account_id
             WHERE i.account_id=:account_id
               AND i.expires_at>UTC_TIMESTAMP()
             ORDER BY i.created_at DESC'
        );
        $stmt->execute(['account_id'=>$accountId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
