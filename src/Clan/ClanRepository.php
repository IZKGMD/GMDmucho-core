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
        $stmt=$this->pdo->prepare(
            "INSERT INTO mucho_clan_members (clan_id, account_id, role)
             VALUES (:clan_id, :account_id, 'member')"
        );

        return $stmt->execute([
            'clan_id'=>$clanId,
            'account_id'=>$accountId,
        ]);
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

    public function setRole(int $clanId, int $accountId, string $role): bool
    {
        $stmt=$this->pdo->prepare(
            'UPDATE mucho_clan_members
             SET role=:role
             WHERE clan_id=:clan_id AND account_id=:account_id'
        );
        $stmt->execute([
            'role'=>$role,
            'clan_id'=>$clanId,
            'account_id'=>$accountId,
        ]);

        return $stmt->rowCount()>0;
    }

    public function invite(int $clanId, int $accountId, int $invitedBy): bool
    {
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
