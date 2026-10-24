<?php

declare (strict_types=1);
namespace MuchoCore\Interaction;

use PDO;
final class CommentRepository
{
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    public function addLevelComment(int $levelId, int $accountId, string $content, int $percent): int
    {
        $stmt = $this->db->prepare("INSERT INTO comments (level_id, account_id, content, percent, created_at)\n             VALUES (:level_id, :account_id, :content, :percent, NOW())");
        $stmt->execute([":level_id" => $levelId, ":account_id" => $accountId, ":content" => $content, ":percent" => $percent]);
        return (int) $this->db->lastInsertId();
    }
    public function countLevelComments(int $levelId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM comments WHERE level_id=:level_id');
        $stmt->execute(['level_id' => $levelId]);
        return (int) $stmt->fetchColumn();
    }
    public function getLevelComments(int $levelId, int $page = 0, int $limit = 100, bool $orderByLikes = false): array
    {
        $limit = min(100, max(1, $limit));
        $page = min(1000, max(0, $page));
        $offset = $page * $limit;
        $order = $orderByLikes ? 'c.likes DESC, c.id DESC' : 'c.id DESC';
        $stmt = $this->db->prepare("SELECT c.*,\n                    a.username, a.role,\n                    COALESCE(p.user_id, a.account_id) AS user_id,\n                    COALESCE(p.cube, 1) AS cube,\n                    COALESCE(p.color1, 0) AS color1,\n                    COALESCE(p.color2, 3) AS color2,\n                    COALESCE(p.special, 0) AS special,\n                    COALESCE(p.icon_type, 0) AS icon_type,\n                    COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=a.account_id LIMIT 1), '') AS clan_tag\n             FROM comments c\n             JOIN accounts a ON c.account_id = a.account_id\n             LEFT JOIN profiles p ON a.account_id = p.account_id\n             WHERE c.level_id = :level_id\n             ORDER BY {$order}\n             LIMIT :limit OFFSET :offset");
        $stmt->bindValue(":level_id", $levelId, PDO::PARAM_INT);
        $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
        $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function countUserComments(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*)\n             FROM comments c\n             INNER JOIN accounts a\n                 ON a.account_id = c.account_id\n             LEFT JOIN profiles p\n                 ON p.account_id = c.account_id\n             INNER JOIN levels l\n                 ON l.level_id = c.level_id\n             WHERE COALESCE(p.user_id, a.account_id) = :user_id\n               AND l.is_unlisted = 0\n               AND l.unlisted2 = 0\n               AND l.is_deleted = 0");
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }
    public function getUserComments(int $userId, int $page = 0, int $limit = 10, bool $orderByLikes = false): array
    {
        $offset = max(0, $page) * $limit;
        $order = $orderByLikes ? 'c.likes DESC, c.id DESC' : 'c.id DESC';
        $stmt = $this->db->prepare("SELECT\n                    c.*,\n                    l.level_id AS commented_level_id,\n                    a.username,\n                    a.role,\n                    COALESCE(p.user_id, a.account_id) AS user_id,\n                    COALESCE(p.cube, 1) AS cube,\n                    COALESCE(p.color1, 0) AS color1,\n                    COALESCE(p.color2, 3) AS color2,\n                    COALESCE(p.special, 0) AS special,\n                    COALESCE(p.icon_type, 0) AS icon_type,\n                    COALESCE((SELECT c2.tag FROM mucho_clan_members cm2 INNER JOIN mucho_clans c2 ON c2.clan_id=cm2.clan_id WHERE cm2.account_id=a.account_id LIMIT 1), '') AS clan_tag\n             FROM comments c\n             INNER JOIN accounts a\n                 ON a.account_id = c.account_id\n             LEFT JOIN profiles p\n                 ON p.account_id = c.account_id\n             INNER JOIN levels l\n                 ON l.level_id = c.level_id\n             WHERE COALESCE(p.user_id, a.account_id) = :user_id\n               AND l.is_unlisted = 0\n               AND l.unlisted2 = 0\n               AND l.is_deleted = 0\n             ORDER BY {$order}\n             LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function addAccountComment(int $accountId, string $content): int
    {
        $stmt = $this->db->prepare("INSERT INTO account_comments (account_id, content, created_at)\n             VALUES (:account_id, :content, NOW())");
        $stmt->execute([":account_id" => $accountId, ":content" => $content]);
        return (int) $this->db->lastInsertId();
    }
    public function countAccountComments(int $accountId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM account_comments WHERE account_id=:account_id');
        $stmt->execute(['account_id' => $accountId]);
        return (int) $stmt->fetchColumn();
    }
    public function getAccountComments(int $accountId, int $page = 0, int $limit = 100): array
    {
        $limit = min(100, max(1, $limit));
        $page = min(1000, max(0, $page));
        $offset = $page * $limit;
        $stmt = $this->db->prepare("SELECT c.*,\n                    a.role,\n                    COALESCE(p.user_id, a.account_id) AS user_id\n             FROM account_comments c\n             JOIN accounts a ON c.account_id = a.account_id\n             LEFT JOIN profiles p ON p.account_id = a.account_id\n             WHERE c.account_id = :account_id\n             ORDER BY c.created_at DESC\n             LIMIT :limit OFFSET :offset");
        $stmt->bindValue(":account_id", $accountId, PDO::PARAM_INT);
        $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
        $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function deleteLevelComment(int $commentId, int $accountId): bool
    {
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT id
                 FROM comments
                 WHERE id=:id
                   AND account_id=:aid
                 FOR UPDATE');
            $q->execute(['id' => $commentId, 'aid' => $accountId]);
            if (!$q->fetchColumn()) {
                $this->db->rollBack();
                return false;
            }
            $q = $this->db->prepare('DELETE FROM likes
                 WHERE item_id=:id
                   AND type=2');
            $q->execute(['id' => $commentId]);
            $q = $this->db->prepare('DELETE FROM comments
                 WHERE id=:id
                   AND account_id=:aid');
            $q->execute(['id' => $commentId, 'aid' => $accountId]);
            $ok = $q->rowCount() === 1;
            $this->db->commit();
            return $ok;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    public function deleteAccountComment(int $commentId, int $accountId): bool
    {
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT id
                 FROM account_comments
                 WHERE id=:id
                   AND account_id=:aid
                 FOR UPDATE');
            $q->execute(['id' => $commentId, 'aid' => $accountId]);
            if (!$q->fetchColumn()) {
                $this->db->rollBack();
                return false;
            }
            $q = $this->db->prepare('DELETE FROM likes
                 WHERE item_id=:id
                   AND type=3');
            $q->execute(['id' => $commentId]);
            $q = $this->db->prepare('DELETE FROM account_comments
                 WHERE id=:id
                   AND account_id=:aid');
            $q->execute(['id' => $commentId, 'aid' => $accountId]);
            $ok = $q->rowCount() === 1;
            $this->db->commit();
            return $ok;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    private $db;
}
