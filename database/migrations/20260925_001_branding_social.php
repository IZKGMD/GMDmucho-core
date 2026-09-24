<?php

declare(strict_types=1);

return [
    <<<'SQL'
ALTER TABLE mucho_branding
    ADD COLUMN server_by_name VARCHAR(64) NULL AFTER server_name,
    ADD COLUMN social_url VARCHAR(512) NULL AFTER server_by_name
SQL,
];
