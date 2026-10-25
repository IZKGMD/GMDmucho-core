<?php

declare(strict_types=1);

/** Repair original rating fields skipped when a native levels table existed. */
return static function (PDO $db): void {
    $existing = $db->query('SHOW COLUMNS FROM levels')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['custom_difficulty', 'rate_tier', 'custom_rate_tier'] as $column) {
        if (!in_array($column, $existing, true)) {
            $db->exec('ALTER TABLE levels ADD COLUMN `' . $column . '` SMALLINT UNSIGNED NOT NULL DEFAULT 0');
        }
    }
};
