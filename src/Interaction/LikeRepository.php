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
            4 => ['mucho_level_lists', 'list_id'],
            default => throw new RuntimeException('Invalid like type'),
        };

        if ($itemId <= 0 || $accountId <= 0) {
            return false;
        }

        $this->db->beginTransaction();

        try {
            // Lock the target first so a vote can never create an orphan row.
            $target = $this->db->prepare(
                "SELECT likes FROM \`$table\` WHERE \`$pk\`=:id LIMIT 1 FOR UPDATE"
            );
            $target->execute(['id' => $itemId]);

            if ($target->fetchColumn() === false) {
                $this->db->rollBack();
                return false;
            }

            $current = $this->db->prepare(
                'SELECT id, is_like
                 FROM likes
                 WHERE item_id=:item
                   AND type=:type
                   AND account_id=:account
                 LIMIT 1
                 FOR UPDATE'
            );
            $current->execute([
                'item' => $itemId,
                'type' => $type,
                'account' => $accountId,
            ]);

            $row = $current->fetch(PDO::FETCH_ASSOC);
            $newLike = $isLike ? 1 : 0;

            if ($row !== false) {
                $oldLike = (int)$row['is_like'];

                if ($oldLike === $newLike) {
                    $this->db->commit();
                    return false;
                }

                $updateVote = $this->db->prepare(
                    'UPDATE likes
                     SET is_like=:is_like, created_at=CURRENT_TIMESTAMP
                     WHERE id=:id'
                );
                $updateVote->execute([
                    'is_like' => $newLike,
                    'id' => (int)$row['id'],
                ]);

                $delta = $newLike === 1 ? 1 : -1;
            } else {
                // An "unlike" without a previous like is a no-op.
                if ($newLike === 0) {
                    $this->db->commit();
                    return false;
                }

                $insert = $this->db->prepare(
                    'INSERT INTO likes
                     (item_id,type,account_id,is_like)
                     VALUES (:item,:type,:account,:like)'
                );
                $insert->execute([
                    'item' => $itemId,
                    'type' => $type,
                    'account' => $accountId,
                    'like' => 1,
                ]);

                $delta = 1;
            }

            $q = $this->db->prepare(
                "UPDATE \`$table\`
                 SET likes = GREATEST(0, likes + :amount)
                 WHERE \`$pk\` = :id"
            );
            $q->execute([
                'amount' => $delta,
                'id' => $itemId,
            ]);

            if ($q->rowCount() !== 1) {
                throw new RuntimeException('Like target changed during vote.');
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
