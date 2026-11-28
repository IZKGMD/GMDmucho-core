<?php
declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS mucho_cvolton_content_map (
        dataset VARCHAR(20) NOT NULL,
        source_id BIGINT UNSIGNED NOT NULL,
        target_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (dataset, source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
