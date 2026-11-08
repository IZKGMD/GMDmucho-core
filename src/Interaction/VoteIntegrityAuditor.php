<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use PDO;
use RuntimeException;

/**
 * Read-only vote integrity audit. Never repairs scores or rewrites vote history.
 *
 * Works in bounded, indexed batches so the diagnostic can run on an existing
 * GDPS without loading the entire vote table into memory.
 */
final readonly class VoteIntegrityAuditor
{
    /** @var array<int,array{0:string,1:string}> */
    private const TARGETS = [
        1 => ['levels', 'level_id'],
        2 => ['comments', 'id'],
        3 => ['account_comments', 'id'],
        4 => ['mucho_level_lists', 'list_id'],
    ];

    public function __construct(private PDO $db) {}

    public function scan(
        int $maxTargetsPerType = 5000,
        int $maxVotes = 20000,
        int $batchSize = 200
    ): array {
        if ($maxTargetsPerType < 1 || $maxVotes < 1 ||
            $batchSize < 1 || $batchSize > 500) {
            throw new RuntimeException('Invalid vote audit limits.');
        }

        if (!$this->tableExists('likes')) {
            throw new RuntimeException('Missing likes table.');
        }

        if ($this->db->inTransaction()) {
            throw new RuntimeException('Audit requires its own consistent snapshot.');
        }
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->beginTransaction();

        try {
        $summary = [
            'read_only' => true,
            'schema' => [
                'actor_unique_index' => $this->hasActorUniqueIndex(),
            ],
            'targets' => [],
            'votes' => [
                'scanned' => 0,
                'complete' => false,
                'orphaned' => 0,
                'self_votes' => 0,
                'invalid_values' => 0,
                'unknown_types' => 0,
            ],
        ];

        foreach (self::TARGETS as $type => [$table, $pk]) {
            if (!$this->tableExists($table)) {
                $summary['targets'][$type] = [
                    'table' => $table,
                    'exists' => false,
                    'scanned' => 0,
                    'complete' => false,
                    'mismatches' => 0,
                    'samples' => [],
                ];
                continue;
            }

            $item = [
                'table' => $table,
                'exists' => true,
                'scanned' => 0,
                'complete' => false,
                'mismatches' => 0,
                'samples' => [],
            ];
            $cursor = 0;
            do {
                $take = min($batchSize, $maxTargetsPerType - $item['scanned']);
                $q = $this->db->prepare(
                    "SELECT t.\`$pk\` AS item_id, t.likes AS stored_score,
                            COALESCE(SUM(CASE
                                WHEN v.is_like=1 THEN 1
                                WHEN v.is_like=0 THEN -1
                                ELSE 0 END),0) AS expected_score
                     FROM \`$table\` t
                     LEFT JOIN likes v ON v.item_id=t.\`$pk\` AND v.type=:type
                     WHERE t.\`$pk\`>:cursor
                     GROUP BY t.\`$pk\`,t.likes
                     ORDER BY t.\`$pk\` ASC
                     LIMIT :take"
                );
                $q->bindValue(':type', $type, PDO::PARAM_INT);
                $q->bindValue(':cursor', $cursor, PDO::PARAM_INT);
                $q->bindValue(':take', $take, PDO::PARAM_INT);
                $q->execute();
                $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $cursor = (int)$row['item_id'];
                    $actual = (int)$row['stored_score'];
                    $expected = (int)$row['expected_score'];
                    $item['scanned']++;
                    if ($actual !== $expected) {
                        $item['mismatches']++;
                        if (count($item['samples']) < 10) {
                            $item['samples'][] = [
                                'item_id' => $cursor,
                                'stored' => $actual,
                                'expected' => $expected,
                            ];
                        }
                    }
                }
                if (count($rows) < $take) {
                    $item['complete'] = true;
                    break;
                }
            } while ($item['scanned'] < $maxTargetsPerType);

            $summary['targets'][$type] = $item;
        }

        $cursor = 0;
        do {
            $take = min($batchSize, $maxVotes - $summary['votes']['scanned']);
            $q = $this->db->prepare(
                'SELECT id,item_id,type,account_id,is_like
                 FROM likes WHERE id>:cursor ORDER BY id ASC LIMIT :take'
            );
            $q->bindValue(':cursor', $cursor, PDO::PARAM_INT);
            $q->bindValue(':take', $take, PDO::PARAM_INT);
            $q->execute();
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);

            $itemsByType = [];
            foreach ($rows as $row) {
                $cursor = (int)$row['id'];
                $type = (int)$row['type'];
                if (isset(self::TARGETS[$type]) &&
                    ($summary['targets'][$type]['exists'] ?? false)) {
                    $itemsByType[$type][(int)$row['item_id']] = true;
                }
            }

            $owners = [];
            foreach ($itemsByType as $type => $ids) {
                [$table, $pk] = self::TARGETS[$type];
                $idList = array_keys($ids);
                $placeholders = implode(',', array_fill(0, count($idList), '?'));
                $ownerStmt = $this->db->prepare(
                    "SELECT \`$pk\` AS item_id,account_id
                     FROM \`$table\` WHERE \`$pk\` IN ($placeholders)"
                );
                $ownerStmt->execute($idList);
                foreach ($ownerStmt->fetchAll(PDO::FETCH_ASSOC) as $owner) {
                    $owners[$type][(int)$owner['item_id']] = (int)$owner['account_id'];
                }
            }

            foreach ($rows as $row) {
                $summary['votes']['scanned']++;
                $type = (int)$row['type'];
                $itemId = (int)$row['item_id'];
                $accountId = (int)$row['account_id'];
                if ((int)$row['is_like'] !== 0 && (int)$row['is_like'] !== 1) {
                    $summary['votes']['invalid_values']++;
                }
                if (!isset(self::TARGETS[$type])) {
                    $summary['votes']['unknown_types']++;
                    continue;
                }
                if (!($summary['targets'][$type]['exists'] ?? false)) {
                    continue; // Missing optional target table is reported above.
                }
                if (!isset($owners[$type][$itemId])) {
                    $summary['votes']['orphaned']++;
                } elseif ($accountId > 0 && $accountId === $owners[$type][$itemId]) {
                    $summary['votes']['self_votes']++;
                }
            }
            if (count($rows) < $take) {
                $summary['votes']['complete'] = true;
                break;
            }
        } while ($summary['votes']['scanned'] < $maxVotes);

        $this->db->commit();
        return $summary;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function hasActorUniqueIndex(): bool
    {
        $q = $this->db->prepare(
            "SELECT column_name,non_unique
             FROM information_schema.statistics
             WHERE table_schema=DATABASE()
               AND table_name='likes'
               AND index_name='uq_like_item_actor'
             ORDER BY seq_in_index"
        );
        $q->execute();
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (array_column($rows, 'column_name') !== [
            'item_id', 'type', 'account_id', 'ip'
        ]) {
            return false;
        }
        foreach ($rows as $row) {
            if ((int)$row['non_unique'] !== 0) {
                return false;
            }
        }
        return true;
    }

    private function tableExists(string $table): bool
    {
        $q = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name=:name'
        );
        $q->execute(['name' => $table]);
        return (int)$q->fetchColumn() > 0;
    }
}
