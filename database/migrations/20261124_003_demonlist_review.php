<?php
declare(strict_types=1);

return static function (PDO $db): void {
    $columns = array_column($db->query('SHOW COLUMNS FROM mucho_demonlist_records')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $changes = [];
    if (!in_array('revision', $columns, true)) {
        $changes[] = 'ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 1';
    }
    if (!in_array('decision_note', $columns, true)) {
        $changes[] = "ADD COLUMN decision_note VARCHAR(280) NOT NULL DEFAULT ''";
    }
    if ($changes !== []) {
        $db->exec('ALTER TABLE mucho_demonlist_records ' . implode(', ', $changes));
    }
};
