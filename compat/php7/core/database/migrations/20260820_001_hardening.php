<?php

declare (strict_types=1);
/*
 * MuchoCore v3.0
 * Copyright (C) 2026 IZK
 */
return static function (PDO $db) {
    \mucho7_domain_schema_exec($db, "\n        CREATE TABLE IF NOT EXISTS mucho_api_rate_limits (\n            bucket_key CHAR(64) PRIMARY KEY,\n            window_start BIGINT NOT NULL,\n            hits INT UNSIGNED NOT NULL DEFAULT 0,\n            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n                ON UPDATE CURRENT_TIMESTAMP\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");
    \mucho7_domain_schema_exec($db, "\n        CREATE TABLE IF NOT EXISTS mucho_api_metrics_minute (\n            minute_start DATETIME NOT NULL,\n            route VARCHAR(160) NOT NULL,\n            method VARCHAR(12) NOT NULL,\n            status SMALLINT UNSIGNED NOT NULL,\n            requests BIGINT UNSIGNED NOT NULL DEFAULT 0,\n            total_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,\n\n            PRIMARY KEY(\n                minute_start,\n                route,\n                method,\n                status\n            )\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");
    \mucho7_domain_schema_exec($db, "\n        CREATE TABLE IF NOT EXISTS mucho_security_events (\n            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n\n            event_type VARCHAR(64) NOT NULL,\n            ip VARCHAR(45) NOT NULL DEFAULT '',\n            route VARCHAR(255) NOT NULL DEFAULT '',\n            request_id VARCHAR(64) NOT NULL DEFAULT '',\n            metadata TEXT NULL,\n\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n\n            KEY idx_security_time(created_at),\n            KEY idx_security_type(event_type),\n            KEY idx_security_ip(ip)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");
    \mucho7_domain_schema_exec($db, "\n        CREATE TABLE IF NOT EXISTS mucho_system_alerts (\n            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n\n            severity ENUM(\n                'info',\n                'warning',\n                'critical'\n            ) NOT NULL DEFAULT 'warning',\n\n            source VARCHAR(64) NOT NULL,\n            message VARCHAR(500) NOT NULL,\n\n            resolved TINYINT(1) NOT NULL DEFAULT 0,\n\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            resolved_at TIMESTAMP NULL DEFAULT NULL,\n\n            KEY idx_alert_resolved(resolved),\n            KEY idx_alert_created(created_at)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");
};
