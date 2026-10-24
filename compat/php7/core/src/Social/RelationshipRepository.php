<?php

declare (strict_types=1);
namespace MuchoCore\Social;

use PDO;
use Throwable;
final class RelationshipRepository
{
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    private function blocked(int $a, int $b): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM blocks
             WHERE (account_id=:a1 AND blocked_account_id=:b1)
                OR (account_id=:b2 AND blocked_account_id=:a2)
             LIMIT 1');
        $q->execute(['a1' => $a, 'b1' => $b, 'b2' => $b, 'a2' => $a]);
        return (bool) $q->fetchColumn();
    }
    public function isFriend(int $a, int $b): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM friends
             WHERE account_id=:a AND friend_account_id=:b
             LIMIT 1');
        $q->execute(['a' => $a, 'b' => $b]);
        return (bool) $q->fetchColumn();
    }
    public function createRequest(int $from, int $to, string $comment): bool
    {
        if ($from === $to || $this->blocked($from, $to)) {
            return false;
        }
        $q = $this->pdo->prepare('SELECT friend_requests_state
             FROM accounts
             WHERE account_id=:id
               AND is_active=1
               AND is_banned=0');
        $q->execute(['id' => $to]);
        $state = $q->fetchColumn();
        if ($state === false || (int) $state !== 0) {
            return false;
        }
        if ($this->isFriend($from, $to)) {
            return false;
        }
        $q = $this->pdo->prepare('SELECT 1 FROM friend_requests
             WHERE (account_id=:a1 AND to_account_id=:b1)
                OR (account_id=:b2 AND to_account_id=:a2)
             LIMIT 1');
        $q->execute(['a1' => $from, 'b1' => $to, 'b2' => $to, 'a2' => $from]);
        if ($q->fetchColumn()) {
            return false;
        }
        $q = $this->pdo->prepare('INSERT INTO friend_requests
             (account_id,to_account_id,comment)
             VALUES (:a,:b,:comment)');
        return $q->execute(['a' => $from, 'b' => $to, 'comment' => $comment]);
    }
    public function requests(int $accountId, bool $sent, int $page): array
    {
        $offset = max(0, $page) * 10;
        if ($sent) {
            $sql = "SELECT fr.id,fr.account_id,fr.to_account_id,\n                        fr.comment,fr.is_read,fr.created_at,\n                        a.username,p.user_id,p.cube,p.color1,\n                        COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=a.account_id LIMIT 1), '') AS clan_tag,\n                        p.color2,p.special\n                 FROM friend_requests fr\n                 JOIN accounts a\n                   ON a.account_id=fr.to_account_id\n                 JOIN profiles p\n                   ON p.account_id=fr.to_account_id\n                 WHERE fr.account_id=:id\n                 ORDER BY fr.id DESC\n                 LIMIT 10 OFFSET {$offset}";
        } else {
            $sql = "SELECT fr.id,fr.account_id,fr.to_account_id,\n                        fr.comment,fr.is_read,fr.created_at,\n                        a.username,p.user_id,p.cube,p.color1,\n                        COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=a.account_id LIMIT 1), '') AS clan_tag,\n                        p.color2,p.special\n                 FROM friend_requests fr\n                 JOIN accounts a\n                   ON a.account_id=fr.account_id\n                 JOIN profiles p\n                   ON p.account_id=fr.account_id\n                 WHERE fr.to_account_id=:id AND fr.is_read<>2\n                 ORDER BY fr.id DESC\n                 LIMIT 10 OFFSET {$offset}";
        }
        $q = $this->pdo->prepare($sql);
        $q->execute(['id' => $accountId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function requestCount(int $accountId, bool $sent): int
    {
        $column = $sent ? 'account_id' : 'to_account_id';
        $q = $this->pdo->prepare("SELECT COUNT(*)\n             FROM friend_requests\n             WHERE {$column}=:id" . ($sent ? '' : ' AND is_read<>2'));
        $q->execute(['id' => $accountId]);
        return (int) $q->fetchColumn();
    }
    public function readRequest(int $accountId, int $requestId): bool
    {
        $exists = $this->pdo->prepare('SELECT 1
             FROM friend_requests
             WHERE id=:rid AND to_account_id=:aid
             LIMIT 1');
        $exists->execute(['rid' => $requestId, 'aid' => $accountId]);
        if ($exists->fetchColumn() === false) {
            return false;
        }
        $q = $this->pdo->prepare('UPDATE friend_requests
             SET is_read=1
             WHERE id=:rid AND to_account_id=:aid');
        $q->execute(['rid' => $requestId, 'aid' => $accountId]);
        return true;
    }
    public function accept(int $accountId, int $requestId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('SELECT account_id,to_account_id
                 FROM friend_requests
                 WHERE id=:id FOR UPDATE');
            $q->execute(['id' => $requestId]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if (!$r || (int) $r['to_account_id'] !== $accountId) {
                $this->pdo->rollBack();
                return false;
            }
            $other = (int) $r['account_id'];
            if ($this->blocked($accountId, $other)) {
                $this->pdo->rollBack();
                return false;
            }
            $q = $this->pdo->prepare('INSERT IGNORE INTO friends
                 (account_id,friend_account_id,is_new)
                 VALUES
                 (:a1,:b1,1),
                 (:a2,:b2,1)');
            $q->execute(['a1' => $accountId, 'b1' => $other, 'a2' => $other, 'b2' => $accountId]);
            $q = $this->pdo->prepare('DELETE FROM friend_requests WHERE id=:id');
            $q->execute(['id' => $requestId]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
    public function deleteRequest(int $accountId, int $target, bool $sent): bool
    {
        if ($sent) {
            $sql = 'DELETE FROM friend_requests
                 WHERE account_id=:me
                   AND to_account_id=:target';
        } else {
            $sql = 'DELETE FROM friend_requests
                 WHERE to_account_id=:me
                   AND account_id=:target';
        }
        $q = $this->pdo->prepare($sql);
        $q->execute(['me' => $accountId, 'target' => $target]);
        return $q->rowCount() > 0;
    }
    public function removeFriend(int $a, int $b): bool
    {
        $q = $this->pdo->prepare('DELETE FROM friends
             WHERE
               (account_id=:a1 AND friend_account_id=:b1)
               OR
               (account_id=:b2 AND friend_account_id=:a2)');
        $q->execute(['a1' => $a, 'b1' => $b, 'b2' => $b, 'a2' => $a]);
        return $q->rowCount() > 0;
    }
    public function block(int $a, int $b): bool
    {
        if ($a === $b) {
            return false;
        }
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('INSERT IGNORE INTO blocks
                 (account_id,blocked_account_id)
                 VALUES (:a,:b)');
            $q->execute(['a' => $a, 'b' => $b]);
            $q = $this->pdo->prepare('DELETE FROM friends
                 WHERE
                   (account_id=:a1 AND friend_account_id=:b1)
                   OR
                   (account_id=:b2 AND friend_account_id=:a2)');
            $q->execute(['a1' => $a, 'b1' => $b, 'b2' => $b, 'a2' => $a]);
            $q = $this->pdo->prepare('DELETE FROM friend_requests
                 WHERE
                   (account_id=:a1 AND to_account_id=:b1)
                   OR
                   (account_id=:b2 AND to_account_id=:a2)');
            $q->execute(['a1' => $a, 'b1' => $b, 'b2' => $b, 'a2' => $a]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
    public function unblock(int $a, int $b): bool
    {
        $q = $this->pdo->prepare('DELETE FROM blocks
             WHERE account_id=:a
               AND blocked_account_id=:b');
        $q->execute(['a' => $a, 'b' => $b]);
        return $q->rowCount() > 0;
    }
    public function userList(int $accountId, int $type): array
    {
        if (!\mucho7_domain_read_only() && $type === 0) {
            $q = $this->pdo->prepare("SELECT a.username,p.user_id,p.cube,p.color1,\n                        p.color2,p.special,\n                        p.stars,p.demons,p.diamonds,p.moons,\n                        p.creator_points,p.secret_coins,p.user_coins,\n                        COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=a.account_id LIMIT 1), '') AS clan_tag,\n                        f.friend_account_id AS account_id,\n                        f.is_new\n                 FROM friends f\n                 JOIN accounts a\n                   ON a.account_id=f.friend_account_id\n                 JOIN profiles p\n                   ON p.account_id=f.friend_account_id\n                 WHERE f.account_id=:id\n                 ORDER BY a.username ASC");
        } elseif ($type === 1) {
            $q = $this->pdo->prepare("SELECT a.username,p.user_id,p.cube,p.color1,\n                        p.color2,p.special,\n                        p.stars,p.demons,p.diamonds,p.moons,\n                        p.creator_points,p.secret_coins,p.user_coins,\n                        COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=a.account_id LIMIT 1), '') AS clan_tag,\n                        b.blocked_account_id AS account_id,\n                        0 AS is_new\n                 FROM blocks b\n                 JOIN accounts a\n                   ON a.account_id=b.blocked_account_id\n                 JOIN profiles p\n                   ON p.account_id=b.blocked_account_id\n                 WHERE b.account_id=:id\n                 ORDER BY a.username ASC");
        } elseif ($type === 3) {
            // GD 2.209 getGJUserList20 type=3: users followed by viewer.
            $q = $this->pdo->prepare("SELECT a.username,p.user_id,p.cube,p.color1,p.color2,p.special,\n                        p.stars,p.demons,p.diamonds,p.moons,\n                        p.creator_points,p.secret_coins,p.user_coins,\n                        '' AS clan_tag,\n                        f.followed_account_id AS account_id,\n                        0 AS is_new\n                 FROM mucho_follows f\n                 JOIN accounts a ON a.account_id=f.followed_account_id\n                 JOIN profiles p ON p.account_id=f.followed_account_id\n                 WHERE f.follower_account_id=:id\n                   AND a.is_active=1 AND a.is_banned=0\n                 ORDER BY a.username ASC");
        } elseif ($type === 4) {
            // GD 2.209 getGJUserList20 type=4: users muted by viewer.
            $q = $this->pdo->prepare("SELECT a.username,p.user_id,p.cube,p.color1,p.color2,p.special,\n                        p.stars,p.demons,p.diamonds,p.moons,\n                        p.creator_points,p.secret_coins,p.user_coins,\n                        '' AS clan_tag,\n                        m.muted_account_id AS account_id,\n                        0 AS is_new\n                 FROM mucho_muted_users m\n                 JOIN accounts a ON a.account_id=m.muted_account_id\n                 JOIN profiles p ON p.account_id=m.muted_account_id\n                 WHERE m.account_id=:id\n                   AND a.is_active=1 AND a.is_banned=0\n                 ORDER BY a.username ASC");
        } else {
            return [];
        }
        $q->execute(['id' => $accountId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!\mucho7_domain_read_only() && ($type === 0 && $rows !== [])) {
            $this->pdo->prepare('UPDATE friends
                 SET is_new=0
                 WHERE account_id=:id')->execute(['id' => $accountId]);
        }
        return $rows;
    }
    public function canMessage(int $from, int $to): bool
    {
        if ($from === $to || $this->blocked($from, $to)) {
            return false;
        }
        $q = $this->pdo->prepare('SELECT messages_state
             FROM accounts
             WHERE account_id=:id
               AND is_active=1
               AND is_banned=0');
        $q->execute(['id' => $to]);
        $state = $q->fetchColumn();
        if ($state === false || (int) $state === 2) {
            return false;
        }
        if ((int) $state === 1) {
            return $this->isFriend($from, $to);
        }
        return true;
    }
    private $pdo;
}
