<?php

declare (strict_types=1);
namespace MuchoCore\Comment;

use PDO;
use MuchoCore\V71\SchemaInspector;
use RuntimeException;
final class CommentHistoryRepository
{
    private $schema;
    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->schema = new SchemaInspector($db);
    }
    public function byUserId(int $accountId, int $offset, int $count, bool $orderByLikes): array
    {
        if (!$this->schema->tableExists('comments')) {
            throw new RuntimeException('comments table missing');
        }
        $accountJoin = '';
        $username = "'Unknown' AS userName";
        if ($this->schema->tableExists('accounts') && $this->schema->columnExists('accounts', 'account_id') && $this->schema->columnExists('accounts', 'username')) {
            $accountJoin = ' LEFT JOIN accounts a ON a.account_id = c.account_id ';
            $username = "COALESCE(a.username, 'Unknown') AS userName";
        }
        $levelJoin = '';
        $levelWhere = '';
        if ($this->schema->tableExists('levels') && $this->schema->columnExists('levels', 'level_id')) {
            $levelJoin = ' LEFT JOIN levels l ON l.level_id = c.level_id ';
            if ($this->schema->columnExists('levels', 'is_deleted')) {
                $levelWhere .= ' AND COALESCE(l.is_deleted, 0) = 0 ';
            }
            if ($this->schema->columnExists('levels', 'is_unlisted')) {
                $levelWhere .= ' AND COALESCE(l.is_unlisted, 0) = 0 ';
            }
            if ($this->schema->columnExists('levels', 'unlisted2')) {
                $levelWhere .= ' AND COALESCE(l.unlisted2, 0) = 0 ';
            }
        }
        $countStmt = $this->db->prepare("SELECT COUNT(*)\n             FROM comments c\n             {$levelJoin}\n             WHERE c.account_id = :account_id\n             {$levelWhere}");
        $countStmt->execute([':account_id' => $accountId]);
        $total = (int) $countStmt->fetchColumn();
        if ($total === 0) {
            return ['rows' => [], 'total' => 0, 'visible' => 0];
        }
        $order = $orderByLikes ? 'c.likes DESC, c.id DESC' : 'c.id DESC';
        $stmt = $this->db->prepare("SELECT\n                c.level_id AS levelID,\n                c.id AS commentID,\n                UNIX_TIMESTAMP(c.created_at) AS timestamp,\n                c.content AS comment,\n                c.account_id AS userID,\n                c.likes AS likes,\n                c.is_spam AS isSpam,\n                c.percent AS percent,\n\n                {$username},\n\n                0 AS icon,\n                0 AS color1,\n                3 AS color2,\n                0 AS iconType,\n                0 AS special,\n                c.account_id AS extID\n\n             FROM comments c\n             {$accountJoin}\n             {$levelJoin}\n\n             WHERE c.account_id = :account_id\n             {$levelWhere}\n\n             ORDER BY {$order}\n             LIMIT :count OFFSET :offset");
        $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
        $stmt->bindValue(':count', $count, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        return ['rows' => $rows, 'total' => $total, 'visible' => count($rows)];
    }
    private $db;
}
