<?php

declare(strict_types=1);

namespace MuchoCore\Job;

use PDO;
use Throwable;

final readonly class JobQueue
{
    public function __construct(private PDO $pdo) {}

    public function enqueue(
        string $jobType,
        array $payload = [],
        int $delaySeconds = 0
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mucho_jobs(job_type,payload_json,available_at)
             VALUES(:job_type,:payload,:available_at)'
        );

        $stmt->execute([
            'job_type' => substr($jobType, 0, 96),
            'payload' => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            ),
            'available_at' => gmdate(
                'Y-m-d H:i:s',
                time() + max(0, $delaySeconds)
            ),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function reserve(string $workerId): ?array
    {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->query(
                "SELECT *
                 FROM mucho_jobs
                 WHERE status='queued'
                   AND available_at<=NOW()
                 ORDER BY id ASC
                 LIMIT 1
                 FOR UPDATE"
            );

            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE mucho_jobs
                 SET status='running',
                     attempts=attempts+1,
                     locked_at=NOW(),
                     worker_id=:worker,
                     updated_at=CURRENT_TIMESTAMP
                 WHERE id=:id AND status='queued'"
            );
            $update->execute([
                'worker' => substr($workerId, 0, 96),
                'id' => (int)$job['id'],
            ]);

            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return null;
            }

            $this->pdo->commit();

            $job['attempts'] = (int)$job['attempts'] + 1;
            $job['status'] = 'running';
            $job['worker_id'] = $workerId;

            $payload = json_decode(
                (string)$job['payload_json'],
                true
            );
            $job['payload'] = is_array($payload) ? $payload : [];

            return $job;
        } catch (Throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return null;
        }
    }

    public function complete(int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE mucho_jobs
             SET status='done',
                 locked_at=NULL,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=:id"
        );
        $stmt->execute(['id' => $jobId]);
    }

    public function fail(
        int $jobId,
        string $error,
        int $retryDelaySeconds = 30,
        int $maxAttempts = 5
    ): void {
        $retryDelaySeconds = max(5, min(86400, $retryDelaySeconds));

        $stmt = $this->pdo->prepare(
            'SELECT attempts FROM mucho_jobs WHERE id=:id LIMIT 1'
        );
        $stmt->execute(['id' => $jobId]);
        $attempts = (int)$stmt->fetchColumn();

        if ($attempts >= $maxAttempts) {
            $update = $this->pdo->prepare(
                "UPDATE mucho_jobs
                 SET status='failed',
                     last_error=:error,
                     locked_at=NULL,
                     updated_at=CURRENT_TIMESTAMP
                 WHERE id=:id"
            );
            $update->execute([
                'id' => $jobId,
                'error' => substr($error, 0, 2000),
            ]);
            return;
        }

        $update = $this->pdo->prepare(
            "UPDATE mucho_jobs
             SET status='queued',
                 available_at=:available_at,
                 last_error=:error,
                 locked_at=NULL,
                 worker_id=NULL,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=:id"
        );
        $update->execute([
            'id' => $jobId,
            'available_at' => gmdate(
                'Y-m-d H:i:s',
                time() + $retryDelaySeconds
            ),
            'error' => substr($error, 0, 2000),
        ]);
    }

    public function retryStale(int $minutes = 10): int
    {
        $minutes = max(1, min(1440, $minutes));

        $stmt = $this->pdo->prepare(
            "UPDATE mucho_jobs
             SET status='queued',
                 worker_id=NULL,
                 locked_at=NULL,
                 available_at=NOW(),
                 updated_at=CURRENT_TIMESTAMP
             WHERE status='running'
               AND locked_at < :cutoff"
        );

        $stmt->execute([
            'cutoff' => gmdate(
                'Y-m-d H:i:s',
                time() - ($minutes * 60)
            ),
        ]);

        return $stmt->rowCount();
    }
}
