<?php
declare(strict_types=1);

namespace MuchoCore\Comment;

use PDO;
use MuchoCore\V71\SchemaInspector;
use RuntimeException;

final class CommentHistoryRepository
{
    private SchemaInspector $schema;

    public function __construct(private readonly PDO $db)
    {
        $this->schema = new SchemaInspector($db);
    }

    public function byUserId(
        int $userId,
        int $offset,
        int $count,
        bool $orderByLikes
    ): array {
        return $this->find($userId, $offset, $count, $orderByLikes, false);
    }

    public function byAccountId(
        int $accountId,
        int $offset,
        int $count,
        bool $orderByLikes
    ): array {
        return $this->find($accountId, $offset, $count, $orderByLikes, true);
    }

    private function find(
        int $identity,
        int $offset,
        int $count,
        bool $orderByLikes,
        bool $byAccountId
    ): array {
        if (!$this->schema->tableExists('comments')) {
            throw new RuntimeException('comments table missing');
        }

        $accountJoin = '';
        $username = "'Unknown' AS userName";

        if (
            $this->schema->tableExists('accounts') &&
            $this->schema->columnExists('accounts', 'account_id') &&
            $this->schema->columnExists('accounts', 'username')
        ) {
            $accountJoin =
                ' INNER JOIN accounts a ON a.account_id = c.account_id ';

            $username =
                "COALESCE(a.username, 'Unknown') AS userName";
        }

        $profileJoin = '';
        $userId = 'c.account_id';
        $appearance = [
            'icon' => '1', 'color1' => '0', 'color2' => '3',
            'iconType' => '0', 'special' => '0',
        ];
        if ($this->schema->tableExists('profiles') &&
            $this->schema->columnExists('profiles', 'account_id')) {
            $profileJoin = ' LEFT JOIN profiles p ON p.account_id = c.account_id ';
            if ($this->schema->columnExists('profiles', 'user_id')) {
                $userId = 'COALESCE(p.user_id, c.account_id)';
            }
            foreach ([
                'icon' => ['cube', 'icon_id'],
                'color1' => ['color1'], 'color2' => ['color2'],
                'iconType' => ['icon_type'], 'special' => ['special', 'glow'],
            ] as $alias => $columns) {
                foreach ($columns as $column) {
                    if ($this->schema->columnExists('profiles', $column)) {
                        $appearance[$alias] = "COALESCE(p.{$column}, {$appearance[$alias]})";
                        break;
                    }
                }
            }
        }
        $identityColumn = $byAccountId ? 'c.account_id' : $userId;

        $levelJoin = '';
        $levelWhere = '';

        if (
            $this->schema->tableExists('levels') &&
            $this->schema->columnExists('levels', 'level_id')
        ) {
            $levelJoin =
                ' INNER JOIN levels l ON l.level_id = c.level_id ';

            if ($this->schema->columnExists('levels', 'is_deleted')) {
                $levelWhere .= ' AND COALESCE(l.is_deleted, 0) = 0 ';
            }

            if ($this->schema->columnExists('levels', 'is_unlisted')) {
                $levelWhere .= ' AND COALESCE(l.is_unlisted, 0) = 0 ';
            }
        } else {
            // A public history must not expose comments without a live level.
            throw new RuntimeException('levels table missing');
        }

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM comments c
             {$accountJoin}
             {$profileJoin}
             {$levelJoin}
             WHERE {$identityColumn} = :identity
             {$levelWhere}"
        );

        $countStmt->execute([
            ':identity' => $identity
        ]);

        $total = (int)$countStmt->fetchColumn();

        if ($total === 0) {
            return [
                'rows' => [],
                'total' => 0,
                'visible' => 0
            ];
        }

        $order = $orderByLikes
            ? 'c.likes DESC, c.id DESC'
            : 'c.id DESC';

        $stmt = $this->db->prepare(
            "SELECT
                c.level_id AS levelID,
                c.id AS commentID,
                UNIX_TIMESTAMP(c.created_at) AS timestamp,
                c.content AS comment,
                {$userId} AS userID,
                c.likes AS likes,
                c.is_spam AS isSpam,
                c.percent AS percent,

                {$username},

                {$appearance['icon']} AS icon,
                {$appearance['color1']} AS color1,
                {$appearance['color2']} AS color2,
                {$appearance['iconType']} AS iconType,
                {$appearance['special']} AS special,
                c.account_id AS extID

             FROM comments c
             {$accountJoin}
             {$profileJoin}
             {$levelJoin}

             WHERE {$identityColumn} = :identity
             {$levelWhere}

             ORDER BY {$order}
             LIMIT :count OFFSET :offset"
        );

        $stmt->bindValue(
            ':identity',
            $identity,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ':count',
            $count,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ':offset',
            $offset,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];

        return [
            'rows' => $rows,
            'total' => $total,
            'visible' => count($rows)
        ];
    }
}
