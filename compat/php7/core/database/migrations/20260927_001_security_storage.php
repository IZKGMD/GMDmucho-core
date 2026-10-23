<?php

declare (strict_types=1);
use MuchoCore\Database\Migration;
use PDO;
return new class implements Migration
{
    public function up(PDO $pdo)
    {
        \mucho7_domain_schema_exec($pdo, 'CREATE TABLE IF NOT EXISTS mucho_security_rate_limits (
            bucket_key CHAR(64) NOT NULL PRIMARY KEY,
            window_start BIGINT UNSIGNED NOT NULL DEFAULT 0,
            request_count INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_msrl_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        \mucho7_domain_schema_exec($pdo, 'CREATE TABLE IF NOT EXISTS mucho_security_penalties (
            bucket_key CHAR(64) NOT NULL PRIMARY KEY,
            expires_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            strikes TINYINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_msp_updated (updated_at),
            KEY idx_msp_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    public function down(PDO $pdo)
    {
        \mucho7_domain_schema_exec($pdo, 'DROP TABLE IF EXISTS mucho_security_penalties');
        \mucho7_domain_schema_exec($pdo, 'DROP TABLE IF EXISTS mucho_security_rate_limits');
    }
};
