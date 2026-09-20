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
        if ($itemId <= 0 || $accountId <= 0) {
            return false;
        }

        [$table, $pk] = match ($type) {
            1 => ['levels', 'level_id'],
            2 => ['comments', 'id'],
            3 => ['account_comments', 'id'],
            default => throw new RuntimeException('Invalid like type'),
        };

        $this->db->beginTransaction();

        try {
            $target = $this->db->prepare(
                "SELECT likes
                 FROM {$table}
                 WHERE {$pk}=:id
                 LIMIT 1
                 FOR UPDATE"
            );
            $target->execute(['id' => $itemId]);

            $currentLikes = $target->fetchColumn();

            if ($currentLikes === false) {
                $this->db->rollBack();
                return false;
            }

            $vote = $this->db->prepare(
                'SELECT is_like
                 FROM likes
                 WHERE item_id=:item
                   AND type=:type
                   AND account_id=:account
                 LIMIT 1
                 FOR UPDATE'
            );
            $vote->execute([
                'item' => $itemId,
                'type' => $type,
                'account' => $accountId,
            ]);

            $existing = $vote->fetchColumn();
            $desired = $isLike ? 1 : 0;

            if ($existing !== false) {
                $existing = (int)$existing;

                if ($existing === $desired) {
                    $this->db->rollBack();
                    return false;
                }

                $updateVote = $this->db->prepare(
                    'UPDATE likes
                     SET is_like=:is_like
                     WHERE item_id=:item
                       AND type=:type
                       AND account_id=:account'
                );
                $updateVote->execute([
                    'is_like' => $desired,
                    'item' => $itemId,
                    'type' => $type,
                    'account' => $accountId,
                ]);

                $delta = $desired === 1 ? 1 : -1;
            } else {
                $insertVote = $this->db->prepare(
                    'INSERT INTO likes
                     (item_id,type,account_id,is_like)
                     VALUES (:item,:type,:account,:is_like)'
                );
                $insertVote->execute([
                    'item' => $itemId,
                    'type' => $type,
                    'account' => $accountId,
                    'is_like' => $desired,
                ]);

                $delta = $desired === 1 ? 1 : 0;
            }

            $updateTarget = $this->db->prepare(
                "UPDATE {$table}
                 SET likes=GREATEST(0,likes+:amount)
                 WHERE {$pk}=:id"
            );
            $updateTarget->execute([
                'amount' => $delta,
                'id' => $itemId,
            ]);

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
