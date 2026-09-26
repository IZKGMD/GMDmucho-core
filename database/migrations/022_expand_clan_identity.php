<?php

declare(strict_types=1);

return [
    <<<'SQL'
ALTER TABLE mucho_clans
    MODIFY COLUMN name VARCHAR(32) NOT NULL,
    MODIFY COLUMN tag VARCHAR(8) NOT NULL
SQL,
];
