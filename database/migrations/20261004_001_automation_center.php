<?php

declare(strict_types=1);

use MuchoCore\Database\Migration;
use PDO;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_automation_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    job_type VARCHAR(96) NOT NULL,
    interval_seconds INT UNSIGNED NOT NULL DEFAULT 3600,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    next_run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_enqueued_at DATETIME NULL DEFAULT NULL,
    last_job_id BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mas_code (code),
    KEY idx_mas_due (enabled, next_run_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_automation_heartbeat (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    scheduler_id VARCHAR(96) NOT NULL DEFAULT '',
    ticked_at DATETIME NOT NULL,
    enqueued_count INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $defaults = [
            [
                'cleanup',
                'Maintenance cleanup',
                'Remove completed/failed jobs older than 30 days and expired cache rows.',
                'maintenance.cleanup',
                21600,
            ],
            [
                'security-cleanup',
                'Security event cleanup',
                'Prune security events older than 30 days while keeping recent audit data.',
                'security.cleanup',
                86400,
            ],
        ];

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO mucho_automation_schedules
             (code,name,description,job_type,interval_seconds,enabled,next_run_at)
             VALUES (:code,:name,:description,:job_type,:interval,1,NOW())'
        );

        foreach ($defaults as $row) {
            $stmt->execute([
                'code' => $row[0],
                'name' => $row[1],
                'description' => $row[2],
                'job_type' => $row[3],
                'interval' => $row[4],
            ]);
        }

        $pdo->exec(
            "INSERT INTO mucho_automation_heartbeat
             (id,scheduler_id,ticked_at,enqueued_count)
             VALUES (1,'migration',NOW(),0)
             ON DUPLICATE KEY UPDATE ticked_at=VALUES(ticked_at)"
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS mucho_automation_heartbeat');
        $pdo->exec('DROP TABLE IF EXISTS mucho_automation_schedules');
    }
};
