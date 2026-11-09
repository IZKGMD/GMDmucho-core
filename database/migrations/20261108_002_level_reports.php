<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_level_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    level_id BIGINT UNSIGNED NOT NULL,
    reporter_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_mucho_level_report_actor (level_id, reporter_hash),
    KEY idx_mucho_level_reports_created (created_at),

    CONSTRAINT fk_mucho_level_reports_level
        FOREIGN KEY (level_id) REFERENCES levels (level_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
