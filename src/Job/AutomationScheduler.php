<?php

declare(strict_types=1);

namespace MuchoCore\Job;

use PDO;
use RuntimeException;
use Throwable;

final class AutomationScheduler
{
    /** @var array<string,array{name:string,description:string,job_type:string,default_interval:int}> */
    public const DEFINITIONS = [
        'cleanup' => [
            'name' => 'Maintenance cleanup',
            'description' => 'Remove old completed jobs and expired cache rows.',
            'job_type' => 'maintenance.cleanup',
            'default_interval' => 21600,
        ],
        'security-cleanup' => [
            'name' => 'Security event cleanup',
            'description' => 'Prune old security events after the retention period.',
            'job_type' => 'security.cleanup',
            'default_interval' => 86400,
        ],
    ];

    public function __construct(private readonly PDO $pdo) {}

    public function tick(?int $now = null): int
    {
        $now = $now ?? time();
        $lock = 'muchocore:automation-scheduler';

        $stmt = $this->pdo->query(
            'SELECT GET_LOCK(' . $this->pdo->quote($lock) . ', 5)'
        );

        if ((int)$stmt->fetchColumn() !== 1) {
            return 0;
        }

        $enqueued = 0;

        try {
            $this->ensureTables();

            $rows = $this->pdo->query(
                "SELECT id,code,job_type,interval_seconds,next_run_at,enabled
                 FROM mucho_automation_schedules
                 WHERE enabled=1
                   AND next_run_at<=NOW()
                 ORDER BY id ASC
                 LIMIT 20"
            )->fetchAll(PDO::FETCH_ASSOC);

            $queue = new JobQueue($this->pdo);

            foreach ($rows as $row) {
                $code = (string)$row['code'];
                $definition = self::DEFINITIONS[$code] ?? null;

                if ($definition === null || (string)$row['job_type'] !== $definition['job_type']) {
                    continue;
                }

                $interval = max(60, min(2592000, (int)$row['interval_seconds']));
                $jobId = $queue->enqueue(
                    (string)$row['job_type'],
                    [
                        'schedule' => $code,
                        'scheduled_at' => (string)$row['next_run_at'],
                    ]
                );

                $next = max(
                    $now + 60,
                    strtotime((string)$row['next_run_at']) + $interval
                );

                while ($next <= $now) {
                    $next += $interval;
                }

                $update = $this->pdo->prepare(
                    'UPDATE mucho_automation_schedules
                     SET next_run_at=:next,
                         last_enqueued_at=NOW(),
                         last_job_id=:job_id
                     WHERE id=:id AND enabled=1'
                );
                $update->execute([
                    'next' => gmdate('Y-m-d H:i:s', $next),
                    'job_id' => $jobId,
                    'id' => (int)$row['id'],
                ]);

                $enqueued++;
            }

            $heartbeat = $this->pdo->prepare(
                'INSERT INTO mucho_automation_heartbeat
                 (id,scheduler_id,ticked_at,enqueued_count)
                 VALUES (1,:scheduler,NOW(),:count)
                 ON DUPLICATE KEY UPDATE
                    scheduler_id=VALUES(scheduler_id),
                    ticked_at=VALUES(ticked_at),
                    enqueued_count=VALUES(enqueued_count)'
            );
            $heartbeat->execute([
                'scheduler' => (gethostname() ?: 'scheduler') . ':' . getmypid(),
                'count' => $enqueued,
            ]);

            return $enqueued;
        } finally {
            $this->pdo->query(
                'SELECT RELEASE_LOCK(' . $this->pdo->quote($lock) . ')'
            );
        }
    }

    public function schedules(): array
    {
        $this->ensureTables();

        $rows = $this->pdo->query(
            'SELECT id,code,name,description,job_type,interval_seconds,
                    enabled,next_run_at,last_enqueued_at,last_job_id,
                    created_at,updated_at
             FROM mucho_automation_schedules
             ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static function (array $row): array {
                $row['interval_seconds'] = (int)$row['interval_seconds'];
                $row['enabled'] = (bool)$row['enabled'];
                $row['last_job_id'] = $row['last_job_id'] !== null
                    ? (int)$row['last_job_id']
                    : null;
                $row['id'] = (int)$row['id'];
                return $row;
            },
            $rows
        );
    }

    public function setSchedule(
        string $code,
        bool $enabled,
        int $intervalSeconds
    ): void {
        if (!isset(self::DEFINITIONS[$code])) {
            throw new RuntimeException('Unknown automation schedule.');
        }

        $intervalSeconds = max(60, min(2592000, $intervalSeconds));
        $stmt = $this->pdo->prepare(
            'UPDATE mucho_automation_schedules
             SET enabled=:enabled,
                 interval_seconds=:interval
             WHERE code=:code'
        );
        $stmt->execute([
            'enabled' => $enabled ? 1 : 0,
            'interval' => $intervalSeconds,
            'code' => $code,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Automation schedule not found.');
        }
    }

    public function heartbeat(): ?array
    {
        if (!$this->tableExists('mucho_automation_heartbeat')) {
            return null;
        }

        try {
            $row = $this->pdo->query(
                'SELECT scheduler_id,ticked_at,enqueued_count
                 FROM mucho_automation_heartbeat
                 WHERE id=1
                 LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return null;
            }

            return [
                'scheduler_id' => (string)$row['scheduler_id'],
                'ticked_at' => (string)$row['ticked_at'],
                'enqueued_count' => (int)$row['enqueued_count'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function ensureTables(): void
    {
        if (!$this->tableExists('mucho_automation_schedules')) {
            throw new RuntimeException(
                'Automation Center schema is not installed. Run database migrations.'
            );
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1
                 FROM information_schema.tables
                 WHERE table_schema=DATABASE()
                   AND table_name=:table
                 LIMIT 1'
            );
            $stmt->execute(['table' => $table]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
