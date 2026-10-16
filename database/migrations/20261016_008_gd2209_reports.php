<?php

declare(strict_types=1);

/**
 * Account-authenticated abuse reports for the new GD 2.209 content surfaces.
 * Operators can review this queue separately from legacy IP-hashed reports.
 */
return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_content_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reporter_account_id BIGINT UNSIGNED NOT NULL,
    content_type TINYINT UNSIGNED NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL DEFAULT '',
    report_status ENUM('pending','reviewed','closed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reporter_content
        (reporter_account_id,content_type,target_id),
    KEY idx_mucho_content_reports_queue (report_status,created_at,id),
    CONSTRAINT fk_mucho_content_report_account FOREIGN KEY (reporter_account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
