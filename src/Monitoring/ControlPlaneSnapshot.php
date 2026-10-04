<?php

declare(strict_types=1);

namespace MuchoCore\Monitoring;

use PDO;
use Throwable;

final class ControlPlaneSnapshot
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $rootDir,
        private readonly string $controlDir
    ) {}

    public function snapshot(): array
    {
        $started = microtime(true);
        $database = ['ok' => false, 'latency_ms' => null, 'error' => null];
        try {
            $dbStarted = microtime(true);
            $this->pdo->query('SELECT 1')->fetchColumn();
            $database['ok'] = true;
            $database['latency_ms'] = (int) round((microtime(true) - $dbStarted) * 1000);
        } catch (Throwable) {
            $database['error'] = 'Database health check failed.';
        }

        return [
            'generated_at' => gmdate('c'),
            'version' => $this->currentVersion(),
            'transport' => $this->env('MUCHO_TRANSPORT_MODE') ?: 'direct',
            'domain' => $this->env('MUCHO_ACCOUNT_URL'),
            'maintenance' => $this->flag('maintenance.flag'),
            'registrations_disabled' => $this->flag('registrations-disabled.flag'),
            'database' => $database,
            'counts' => [
                'accounts' => $this->countTable('accounts'),
                'levels' => $this->countTable('levels'),
                'comments' => $this->countTable('comments'),
                'songs' => $this->countTable('songs'),
            ],
            'api' => $this->apiMetrics(),
            'jobs' => $this->jobs(),
            'alerts' => $this->alerts(),
            'security' => $this->security(),
            'clients' => $this->clients(),
            'search' => $this->search(),
            'backups' => $this->backups(),
            'migrations' => $this->migrations(),
            'automation' => $this->automation(),
            'logs' => $this->logs(),
            'snapshot_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    private function env(string $key): string
    {
        try {
            if (class_exists(\MuchoCore\Core\Environment::class)) {
                return trim((string) \MuchoCore\Core\Environment::get($key, ''));
            }
        } catch (Throwable) {}
        $value = getenv($key);
        return $value === false ? '' : trim((string) $value);
    }

    private function currentVersion(): string
    {
        $file = rtrim($this->rootDir, '/\\') . DIRECTORY_SEPARATOR . 'VERSION';
        $value = is_file($file) ? trim((string) @file_get_contents($file)) : '';
        return preg_replace('/^v/i', '', $value) ?: 'unknown';
    }

    private function flag(string $name): bool
    {
        return is_file(rtrim($this->controlDir, '/\\') . DIRECTORY_SEPARATOR . $name);
    }

    private function tableExists(string $table): bool
    {
        try {
            $q = $this->pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table LIMIT 1');
            $q->execute(['table' => $table]);
            return $q->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function countTable(string $table): int
    {
        if (!$this->tableExists($table)) return 0;
        try { return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn(); }
        catch (Throwable) { return 0; }
    }

    private function apiMetrics(): array
    {
        $result = ['requests' => 0, 'errors' => 0, 'rate_limited' => 0, 'avg_ms' => 0, 'routes' => []];
        if (!$this->tableExists('mucho_api_metrics_minute')) return $result;
        try {
            $row = $this->pdo->query("SELECT COALESCE(SUM(requests),0) requests, COALESCE(SUM(CASE WHEN status>=500 THEN requests ELSE 0 END),0) errors, COALESCE(SUM(CASE WHEN status=429 THEN requests ELSE 0 END),0) rate_limited, COALESCE(ROUND(SUM(total_ms)/NULLIF(SUM(requests),0)),0) avg_ms FROM mucho_api_metrics_minute WHERE minute_start>=DATE_SUB(NOW(),INTERVAL 60 MINUTE)")->fetch(PDO::FETCH_ASSOC) ?: [];
            $result['requests'] = (int) ($row['requests'] ?? 0);
            $result['errors'] = (int) ($row['errors'] ?? 0);
            $result['rate_limited'] = (int) ($row['rate_limited'] ?? 0);
            $result['avg_ms'] = (int) ($row['avg_ms'] ?? 0);
            $result['routes'] = $this->pdo->query("SELECT route,SUM(requests) requests,COALESCE(ROUND(SUM(total_ms)/NULLIF(SUM(requests),0)),0) avg_ms,SUM(CASE WHEN status>=400 THEN requests ELSE 0 END) failed FROM mucho_api_metrics_minute WHERE minute_start>=DATE_SUB(NOW(),INTERVAL 60 MINUTE) GROUP BY route ORDER BY requests DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {}
        return $result;
    }

    private function jobs(): array
    {
        $result = ['available' => $this->tableExists('mucho_jobs'), 'queued' => 0, 'running' => 0, 'done_24h' => 0, 'failed_24h' => 0, 'recent' => []];
        if (!$result['available']) return $result;
        try {
            foreach ($this->pdo->query("SELECT status,COUNT(*) total FROM mucho_jobs WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $status=(string)$row['status']; $total=(int)$row['total'];
                if ($status==='queued') $result['queued']=$total;
                elseif ($status==='running') $result['running']=$total;
                elseif ($status==='done') $result['done_24h']=$total;
                elseif ($status==='failed') $result['failed_24h']=$total;
            }
            $result['recent']=$this->pdo->query("SELECT id,job_type,status,attempts,locked_at,worker_id,LEFT(COALESCE(last_error,''),180) last_error,updated_at FROM mucho_jobs ORDER BY id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {}
        return $result;
    }

    private function alerts(): array
    {
        $result=['available'=>$this->tableExists('mucho_system_alerts'),'active'=>0,'items'=>[]];
        if (!$result['available']) return $result;
        try {
            $result['active']=(int)$this->pdo->query('SELECT COUNT(*) FROM mucho_system_alerts WHERE resolved=0')->fetchColumn();
            $result['items']=$this->pdo->query("SELECT id,severity,source,message,created_at FROM mucho_system_alerts WHERE resolved=0 ORDER BY FIELD(severity,'critical','warning','info'),created_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {}
        return $result;
    }

    private function security(): array
    {
        $result=['available'=>$this->tableExists('mucho_security_events'),'last_24h'=>0,'types'=>[],'recent'=>[]];
        if (!$result['available']) return $result;
        try {
            $result['last_24h']=(int)$this->pdo->query("SELECT COUNT(*) FROM mucho_security_events WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();
            $result['types']=$this->pdo->query("SELECT event_type,COUNT(*) total FROM mucho_security_events WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) GROUP BY event_type ORDER BY total DESC,event_type ASC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
            $result['recent']=$this->pdo->query("SELECT id,event_type,route,request_id,created_at FROM mucho_security_events ORDER BY id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {}
        return $result;
    }

    private function clients(): array
    {
        $result=['available'=>$this->tableExists('mucho_client_releases'),'releases'=>[],'files'=>[]];
        if ($result['available']) {
            try { $result['releases']=$this->pdo->query("SELECT platform,current_version,minimum_version,maintenance,updated_at,download_url FROM mucho_client_releases ORDER BY platform")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable) {}
        }
        if ($this->tableExists('mucho_client_release_files')) {
            try { $result['files']=$this->pdo->query("SELECT platform,version,file_name,size_bytes,sha256,created_at FROM mucho_client_release_files ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable) {}
        }
        return $result;
    }

    private function search(): array
    {
        $result=['available'=>$this->tableExists('mucho_level_search_index'),'indexed'=>0,'levels'=>$this->countTable('levels'),'coverage_percent'=>0,'revisions_24h'=>0,'cache_rows'=>0];
        if ($result['available']) {
            $result['indexed']=$this->countTable('mucho_level_search_index');
            if ($result['levels']>0) $result['coverage_percent']=(int)round(min(100,($result['indexed']/$result['levels'])*100));
        }
        if ($this->tableExists('mucho_level_revisions')) {
            try { $result['revisions_24h']=(int)$this->pdo->query("SELECT COUNT(*) FROM mucho_level_revisions WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn(); } catch (Throwable) {}
        }
        if ($this->tableExists('mucho_cache')) $result['cache_rows']=$this->countTable('mucho_cache');
        return $result;
    }

    private function automation(): array
    {
        $result = [
            'available' => $this->tableExists('mucho_automation_schedules'),
            'enabled' => 0,
            'total' => 0,
            'heartbeat' => null,
            'heartbeat_age_seconds' => null,
        ];

        if ($result['available']) {
            try {
                $result['total'] = (int)$this->pdo->query(
                    'SELECT COUNT(*) FROM mucho_automation_schedules'
                )->fetchColumn();
                $result['enabled'] = (int)$this->pdo->query(
                    'SELECT COUNT(*) FROM mucho_automation_schedules WHERE enabled=1'
                )->fetchColumn();
            } catch (Throwable) {
            }
        }

        if ($this->tableExists('mucho_automation_heartbeat')) {
            try {
                $heartbeat = $this->pdo->query(
                    'SELECT scheduler_id,ticked_at,enqueued_count
                     FROM mucho_automation_heartbeat
                     WHERE id=1
                     LIMIT 1'
                )->fetch(PDO::FETCH_ASSOC);

                if ($heartbeat) {
                    $result['heartbeat'] = [
                        'scheduler_id' => (string)$heartbeat['scheduler_id'],
                        'ticked_at' => (string)$heartbeat['ticked_at'],
                        'enqueued_count' => (int)$heartbeat['enqueued_count'],
                    ];

                    $timestamp = strtotime((string)$heartbeat['ticked_at']);
                    if ($timestamp !== false) {
                        $result['heartbeat_age_seconds'] =
                            max(0, time() - $timestamp);
                    }
                }
            } catch (Throwable) {
            }
        }

        return $result;
    }

    private function migrations(): array
    {
        $result = [
            'available' => false,
            'total' => 0,
            'applied' => 0,
            'pending' => 0,
            'latest' => null,
            'latest_applied' => null,
        ];

        $path = rtrim($this->rootDir, '/\\') . '/database/migrations';
        if (!is_dir($path)) {
            return $result;
        }

        $files = glob($path . '/*.php') ?: [];
        sort($files, SORT_STRING);

        $result['available'] = true;
        $result['total'] = count($files);
        $versions = array_map(
            static fn(string $file): string => basename($file, '.php'),
            $files
        );
        $result['latest'] = $versions !== [] ? end($versions) : null;

        if (!$this->tableExists('schema_migrations')) {
            $result['pending'] = $result['total'];
            return $result;
        }

        try {
            $result['applied'] = (int)$this->pdo->query(
                'SELECT COUNT(*) FROM schema_migrations'
            )->fetchColumn();
            $result['pending'] = max(
                0,
                $result['total'] - $result['applied']
            );

            $latest = $this->pdo->query(
                'SELECT version
                 FROM schema_migrations
                 ORDER BY version DESC
                 LIMIT 1'
            )->fetchColumn();

            $result['latest_applied'] =
                is_string($latest) && $latest !== ''
                    ? $latest
                    : null;
        } catch (Throwable) {
        }

        return $result;
    }

    private function backups(): array
    {
        $result=['available'=>$this->tableExists('mucho_backup_verifications'),'last'=>null];
        if (!$result['available']) return $result;
        try { $result['last']=$this->pdo->query("SELECT file_name,size_bytes,sha256,gzip_valid,sql_valid,verified_at FROM mucho_backup_verifications ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable) {}
        return $result;
    }

    private function logs(): array
    {
        $base=rtrim($this->rootDir, '/\\');
        return ['health_age_seconds'=>$this->fileAge($base.'/logs/health.log'),'backup_age_seconds'=>$this->fileAge($base.'/logs/automatic-backup.log')];
    }

    private function fileAge(string $path): ?int
    {
        if (!is_file($path)) return null;
        $mtime=@filemtime($path);
        return $mtime===false ? null : max(0,time()-$mtime);
    }
}