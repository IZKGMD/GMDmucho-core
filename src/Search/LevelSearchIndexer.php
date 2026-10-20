<?php

declare(strict_types=1);

namespace MuchoCore\Search;

use PDO;
use Throwable;

final readonly class LevelSearchIndexer
{
    public function __construct(private PDO $pdo) {}

    public function upsert(int $levelId): bool
    {
        if ($levelId <= 0) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT l.level_id,l.account_id,l.name,l.game_version,
                        l.is_deleted,l.is_unlisted,
                        COALESCE(a.username,'') AS username
                 FROM levels l
                 LEFT JOIN accounts a ON a.account_id=l.account_id
                 WHERE l.level_id=:level_id
                 LIMIT 1"
            );
            $stmt->execute(['level_id' => $levelId]);
            $level = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$level) {
                $this->delete($levelId);
                return false;
            }

            return $this->writeIndexedLevel($level);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Share the same normalization and upsert contract for a single update
     * and for rows already fetched by the batched rebuild.
     */
    private function writeIndexedLevel(array $level): bool
    {
        $name = self::normalize((string)$level['name']);
        $creator = self::normalize((string)$level['username']);

        $write = $this->pdo->prepare(
            'INSERT INTO mucho_level_search_index
                (level_id,account_id,username,normalized_name,
                 normalized_creator,search_text,game_version,
                 is_deleted,is_unlisted)
             VALUES
                (:level_id,:account_id,:username,:name,:creator,
                 :search_text,:game_version,:is_deleted,:is_unlisted)
             ON DUPLICATE KEY UPDATE
                account_id=VALUES(account_id),
                username=VALUES(username),
                normalized_name=VALUES(normalized_name),
                normalized_creator=VALUES(normalized_creator),
                search_text=VALUES(search_text),
                game_version=VALUES(game_version),
                is_deleted=VALUES(is_deleted),
                is_unlisted=VALUES(is_unlisted),
                updated_at=CURRENT_TIMESTAMP'
        );

        $write->execute([
            'level_id' => (int)$level['level_id'],
            'account_id' => (int)$level['account_id'],
            'username' => substr((string)$level['username'], 0, 20),
            'name' => substr($name, 0, 128),
            'creator' => substr($creator, 0, 64),
            'search_text' => substr(trim($name . ' ' . $creator), 0, 255),
            'game_version' => (int)$level['game_version'],
            'is_deleted' => (int)$level['is_deleted'],
            'is_unlisted' => (int)$level['is_unlisted'],
        ]);

        return true;
    }

    public function rebuildAccount(int $accountId): int
    {
        if ($accountId <= 0) {
            return 0;
        }

        try {
            return $this->rebuildBatched($accountId, null);
        } catch (Throwable) {
            return 0;
        }
    }

    public function delete(int $levelId): void
    {
        if ($levelId <= 0) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM mucho_level_search_index WHERE level_id=:level_id'
            );
            $stmt->execute(['level_id' => $levelId]);
        } catch (Throwable) {
        }
    }

    public function rebuild(?int $limit = null): int
    {
        return $this->rebuildBatched(null, $limit);
    }

    /**
     * Reindex in bounded pages instead of loading every level ID into PHP.
     * The high-water ID makes a rebuild finite even as new levels arrive.
     * Keyset pagination avoids growing OFFSET scans on large installations.
     */
    private function rebuildBatched(?int $accountId, ?int $limit): int
    {
        $remaining = $limit === null
            ? PHP_INT_MAX
            : max(1, min(1000000, $limit));

        $scope = $accountId !== null ? ' WHERE account_id=:account_id' : '';
        $ceilingStmt = $this->pdo->prepare('SELECT MAX(level_id) FROM levels' . $scope);
        $ceilingStmt->execute($accountId === null ? [] : ['account_id' => $accountId]);
        $ceiling = (int)$ceilingStmt->fetchColumn();

        if ($ceiling <= 0) {
            return 0;
        }

        // Fetch the index payload in each bounded keyset page. Calling
        // upsert(level_id) here would re-SELECT every row (N+1 reads).
        $scan = $this->pdo->prepare(
            "SELECT l.level_id,l.account_id,l.name,l.game_version,
                    l.is_deleted,l.is_unlisted,
                    COALESCE(a.username,'') AS username
             FROM levels l
             LEFT JOIN accounts a ON a.account_id=l.account_id
             WHERE l.level_id > :cursor AND l.level_id <= :ceiling"
            . ($accountId !== null ? ' AND l.account_id=:account_id' : '')
            . ' ORDER BY l.level_id ASC LIMIT :batch'
        );

        $cursor = 0;
        $indexed = 0;
        while ($remaining > 0) {
            $batch = min(256, $remaining);
            $scan->bindValue(':cursor', $cursor, PDO::PARAM_INT);
            $scan->bindValue(':ceiling', $ceiling, PDO::PARAM_INT);
            $scan->bindValue(':batch', $batch, PDO::PARAM_INT);
            if ($accountId !== null) {
                $scan->bindValue(':account_id', $accountId, PDO::PARAM_INT);
            }
            $scan->execute();
            $rows = $scan->fetchAll(PDO::FETCH_ASSOC);
            $scan->closeCursor();

            if ($rows === []) {
                break;
            }

            foreach ($rows as $level) {
                $cursor = (int)$level['level_id'];
                try {
                    if ($this->writeIndexedLevel($level)) {
                        $indexed++;
                    }
                } catch (Throwable) {
                    // Preserve the existing per-row best-effort rebuild.
                }
            }

            $remaining -= count($rows);
            if (count($rows) < $batch) {
                break;
            }
        }

        return $indexed;
    }

    public function searchIds(string $query, int $limit = 50): array
    {
        $query = self::normalize($query);

        if ($query === '') {
            return [];
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT level_id
                 FROM mucho_level_search_index
                 WHERE is_deleted=0
                   AND is_unlisted=0
                   AND (
                       normalized_name LIKE :prefix
                       OR normalized_creator LIKE :prefix2
                       OR search_text LIKE :contains
                   )
                 ORDER BY
                   CASE
                       WHEN normalized_name=:exact THEN 0
                       WHEN normalized_name LIKE :prefix3 THEN 1
                       WHEN normalized_creator LIKE :prefix4 THEN 2
                       ELSE 3
                   END,
                   updated_at DESC,
                   level_id DESC
                 LIMIT ' . max(1, min(500, $limit))
            );

            $stmt->execute([
                'prefix' => $query . '%',
                'prefix2' => $query . '%',
                'contains' => '%' . $query . '%',
                'exact' => $query,
                'prefix3' => $query . '%',
                'prefix4' => $query . '%',
            ]);

            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            return [];
        }
    }

    public static function normalize(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value))
            ?? trim($value);

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
