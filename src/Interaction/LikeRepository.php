<?php
declare(strict_types=1);

namespace MuchoCore\Interaction;

use PDO;
use RuntimeException;
use Throwable;

final readonly class LikeRepository
{
    public function __construct(private PDO $db) {}

    public function addLike(
        int $itemId,
        int $type,
        int $accountId,
        bool $isLike
    ): bool {
        [$table, $pk] = match ($type) {
            1 => ['levels', 'level_id'],
            2 => ['comments', 'id'],
            3 => ['account_comments', 'id'],
            default => throw new RuntimeException('Invalid like type'),
        };

        $this->db->beginTransaction();

        try {
            $q = $this->db->prepare(
                'INSERT IGNORE INTO likes
                 (item_id,type,account_id,is_like)
                 VALUES (:item,:type,:account,:like)'
            );
            $q->execute([
                'item' => $itemId,
                'type' => $type,
                'account' => $accountId,
                'like' => $isLike ? 1 : 0,
            ]);

            if ($q->rowCount() === 0) {
                $this->db->rollBack();
                return false;
            }

            $q = $this->db->prepare(
                "UPDATE `$table`
                 SET likes = likes + :amount
                 WHERE `$pk` = :id"
            );
            $q->execute([
                'amount' => $isLike ? 1 : -1,
                'id' => $itemId,
            ]);

            if ($q->rowCount() !== 1) {
                throw new RuntimeException('Like target not found');
            }

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
