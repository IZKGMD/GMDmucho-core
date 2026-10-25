<?php

declare (strict_types=1);
namespace MuchoCore\Moderation;

use PDO;
/**
 * Isolated abuse-report queue, not a list of level rating suggestions.
 * No raw IP, credential or account token is stored in report records.
 */
final class ReportQueueRepository
{
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    /**
     * GD 2.209 queues *accounts* with pending abuse cases.
     * A native moderation report is worth 3 abuse points according to the
     * client help text. Filter hits (1 point each, last 7 days) are not
     * present in MuchoCore's persisted moderation data yet; do not invent.
     *
     * @return array{total:int,rows:array<int,array<string,mixed>>}
     */
    public function pending(int $page, int $sort): array
    {
        $page = min(1000, max(0, $page));
        $offset = $page * 20;
        // Do not classify the same user's different levels as separate cases.
        // Reports without a verified target account cannot join the queue.
        $count = $this->pdo->query("SELECT COUNT(DISTINCT target_account_id)\n             FROM mucho_content_reports r\n             WHERE status='pending' AND target_account_id IS NOT NULL\n               AND NOT EXISTS (\n                 SELECT 1 FROM mucho_gd2209_restrictions q\n                 WHERE q.account_id=r.target_account_id AND q.kind='queue'\n                   AND (q.expires_at IS NULL OR q.expires_at > UTC_TIMESTAMP())\n               )");
        $total = (int) $count->fetchColumn();
        $order = (function () use (&$sort) {
            $__mcMatch1 = $sort;
            if ($__mcMatch1 === 1) {
                return 'last_report_id ASC';
            }
            if ($__mcMatch1 === 2) {
                return 'report_count DESC, last_report_id DESC';
            }
            return 'last_report_id DESC';
        })();
        $sql = "SELECT\n                  r.target_account_id,\n                  a.username,\n                  MIN(r.id) AS first_report_id,\n                  MAX(r.id) AS last_report_id,\n                  COUNT(*) AS report_count,\n                  COUNT(DISTINCT r.content_type, r.content_id) AS reported_items,\n                  COUNT(DISTINCT r.reporter_account_id) AS reporter_count,\n                  COUNT(*) * 3 AS report_points,\n                  MAX(r.created_at) AS last_report_at\n                FROM mucho_content_reports r\n                INNER JOIN accounts a ON a.account_id=r.target_account_id\n                WHERE r.status='pending' AND r.target_account_id IS NOT NULL\n                  AND NOT EXISTS (\n                    SELECT 1 FROM mucho_gd2209_restrictions q\n                    WHERE q.account_id=r.target_account_id AND q.kind='queue'\n                      AND (q.expires_at IS NULL OR q.expires_at > UTC_TIMESTAMP())\n                  )\n                GROUP BY r.target_account_id, a.username\n                ORDER BY {$order}\n                LIMIT 20 OFFSET {$offset}";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return ['total' => $total, 'rows' => $rows];
    }
    public function reportLevel(int $reporter, int $levelId, int $reason, string $details): bool
    {
        if ($levelId <= 0) {
            return false;
        }
        $exist = $this->pdo->prepare('SELECT account_id
             FROM levels WHERE level_id=:id AND is_deleted=0 LIMIT 1');
        $exist->execute(['id' => $levelId]);
        $target = (int) ($exist->fetchColumn() ?: 0);
        if ($target <= 0 || $target === $reporter) {
            return false;
        }
        $stmt = $this->pdo->prepare("INSERT INTO mucho_content_reports\n             (reporter_account_id,content_type,content_id,target_account_id,reason,details)\n             VALUES (:reporter,0,:level,:target,:reason,:details)\n             ON DUPLICATE KEY UPDATE id=id");
        $stmt->execute(['reporter' => $reporter, 'level' => $levelId, 'target' => $target, 'reason' => $reason, 'details' => $details]);
        return true;
    }
    private $pdo;
}
