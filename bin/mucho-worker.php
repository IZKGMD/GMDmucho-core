#!/usr/bin/env php
<?php

declare(strict_types=1);

use MuchoCore\Database\Database;
use MuchoCore\Integration\WebhookDispatcher;
use MuchoCore\Job\JobQueue;
use MuchoCore\Search\LevelSearchIndexer;

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

do {
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

            case 'security.cleanup':
                $db->exec(
                    "DELETE FROM mucho_security_events
                     WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
                );
                $db->exec(
                    "DELETE FROM mucho_security_rate_limits
                     WHERE updated_at < DATE_SUB(NOW(), INTERVAL 2 DAY)"
                );
                $db->exec(
                    "DELETE FROM mucho_security_penalties
                     WHERE expires_at > 0
                       AND expires_at < UNIX_TIMESTAMP()"
                );
                break;

            default:
                throw new RuntimeException('Unknown job type: ' . $type);
        }

        $queue->complete($id);
        echo "JOB_OK {$id} {$type}\n";
    } catch (Throwable $e) {
        $queue->fail($id, $e->getMessage());
        fwrite(STDERR, "JOB_FAIL {$id}: {$e->getMessage()}\n");
    }
} while ($loop);
