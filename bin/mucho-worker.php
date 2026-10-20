#!/usr/bin/env php
<?php

declare(strict_types=1);

use MuchoCore\Database\Database;
use MuchoCore\Integration\WebhookDispatcher;
use MuchoCore\Job\JobQueue;
use MuchoCore\Search\LevelSearchIndexer;
use MuchoCore\Level\LevelDownloadTracker;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

if (is_file($root . '/.env')) {
    $lines = file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if (
            strlen($value) >= 2 &&
            (
                ($value[0] === '"' && str_ends_with($value, '"')) ||
                ($value[0] === "'" && str_ends_with($value, "'"))
            )
        ) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

$loop = in_array('--loop', $argv, true);
$sleep = 2;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--sleep=')) {
        $sleep = max(1, min(60, (int)substr($arg, 8)));
    }
}

$db = (new Database())->connection();
$queue = new JobQueue($db);
$indexer = new LevelSearchIndexer($db);
$webhooks = new WebhookDispatcher();
$workerId = (gethostname() ?: 'worker') . ':' . getmypid();

// Keep maintenance outside the request path. Run at most once per minute per
// worker process, pruning a bounded batch via the created_at index.
$nextDownloadPrune = 0;

do {
    if ($loop && time() >= $nextDownloadPrune) {
        $nextDownloadPrune = time() + 60;
        try {
            LevelDownloadTracker::pruneExpired($db, 512);
        } catch (Throwable $e) {
            // Old/incomplete installations may not have the dedupe table.
            // Gameplay must never depend on this optional cleanup.
            error_log('[MuchoCore Worker] dedupe cleanup: ' . $e->getMessage());
        }
    }
    $queue->retryStale();
    $job = $queue->reserve($workerId);

    if ($job === null) {
        if (!$loop) {
            echo "NO_JOBS\n";
            exit(0);
        }
        sleep($sleep);
        continue;
    }

    $id = (int)$job['id'];

    try {
        $type = (string)$job['job_type'];
        $payload = is_array($job['payload'] ?? null)
            ? $job['payload']
            : [];

        switch ($type) {
            case 'level.index':
                $levelId = (int)($payload['level_id'] ?? 0);
                if ($levelId <= 0 || !$indexer->upsert($levelId)) {
                    throw new RuntimeException('Level index job failed.');
                }
                break;

            case 'webhook.dispatch':
                $event = (string)($payload['event'] ?? '');
                $data = is_array($payload['data'] ?? null)
                    ? $payload['data']
                    : [];
                $requestId = (string)($payload['request_id'] ?? '');

                if ($event === '') {
                    throw new RuntimeException('Webhook event is missing.');
                }

                if (!$webhooks->dispatch($event, $data, $requestId)) {
                    throw new RuntimeException('Webhook delivery failed.');
                }
                break;

            case 'maintenance.cleanup':
                $db->exec(
                    "DELETE FROM mucho_jobs
                     WHERE status IN ('done','failed')
                       AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
                );
                $db->exec(
                    "DELETE FROM mucho_cache
                     WHERE expires_at > 0 AND expires_at <= UNIX_TIMESTAMP()"
                );
                break;

            default:
                throw new RuntimeException('Unknown job type: ' . $type);
        }

        $completed = $queue->complete($id, $workerId, (int)$job['attempts']);
        echo $completed ? "JOB_OK {$id} {$type}\n" : "JOB_LEASE_LOST {$id} {$type}\n";
    } catch (Throwable $e) {
        $failed = $queue->fail($id, $e->getMessage(), $workerId, (int)$job['attempts']);
        $result = $failed ? 'JOB_FAIL' : 'JOB_LEASE_LOST';
        fwrite(STDERR, "{$result} {$id}: {$e->getMessage()}\n");
    }
} while ($loop);
