<?php

declare(strict_types=1);

namespace MuchoCore\Moderation;

use PDO;

/**
 * Isolated abuse-report queue, not a list of level rating suggestions.
 * No raw IP, credential or account token is stored in report records.
 */
final readonly class ReportQueueRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array{total:int,rows:array<int,array<string,mixed>>} */
    public function pending(int $page, int $sort): array
    {
        $page = min(1000, max(0, $page));
        $offset = $page * 20;

        $count = $this->pdo->query(
            "SELECT COUNT(*) FROM mucho_content_reports WHERE status='pending'"
        );
        $total = (int)$count->fetchColumn();

        $order = match ($sort) {
            1 => 'r.id ASC',
            2 => 'report_count DESC, r.id DESC',
            default => 'r.id DESC',
        };

        $sql = "SELECT
                r.content_type, r.content_id, r.reason,
                MIN(r.id) AS first_report_id,
                MAX(r.id) AS last_report_id,
                COUNT(*) AS report_count,
                MAX(r.created_at) AS last_report_at
            FROM mucho_content_reports r
            WHERE r.status = 'pending'
            GROUP BY r.content_type, r.content_id, r.reason
            ORDER BY " . ($sort === 1 ? 'last_report_id ASC' :
            ($sort === 2 ? 'report_count DESC, last_report_id DESC' :
                'last_report_id DESC')) .
            " LIMIT 20 OFFSET " . $offset;

        // Grouping by target prevents dozens of duplicates from crowding queue.
        // Count the same distinct items as the SELECT above for correct pagination.
        $totalQuery = $this->pdo->query(
            "SELECT COUNT(*) FROM (
                 SELECT 1 FROM mucho_content_reports
                 WHERE status='pending'
                 GROUP BY content_type, content_id, reason
            ) grouped_reports"
        );
        $total = (int)$totalQuery->fetchColumn();

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return ['total' => $total, 'rows' => $rows];
    }

    public function reportLevel(int $reporter, int $levelId, int $reason, string $details): bool
    {
        if ($levelId <= 0) {
            return false;
        }

        $exist = $this->pdo->prepare(
            'SELECT 1 FROM levels WHERE level_id=:id AND is_deleted=0 LIMIT 1'
        );
        $exist->execute(['id' => $levelId]);
        if ($exist->fetchColumn() === false) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO mucho_content_reports
             (reporter_account_id,content_type,content_id,reason,details)
             VALUES (:reporter,0,:level,:reason,:details)
             ON DUPLICATE KEY UPDATE id=id"
        );
        $stmt->execute([
            'reporter' => $reporter,
            'level' => $levelId,
            'reason' => $reason,
            'details' => $details,
        ]);
        return true;
    }
}
