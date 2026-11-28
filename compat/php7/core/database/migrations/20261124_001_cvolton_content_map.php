<?php

declare (strict_types=1);
return ["CREATE TABLE IF NOT EXISTS mucho_cvolton_content_map (\n        dataset VARCHAR(20) NOT NULL,\n        source_id BIGINT UNSIGNED NOT NULL,\n        target_id BIGINT UNSIGNED NOT NULL,\n        PRIMARY KEY (dataset, source_id)\n    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"];
