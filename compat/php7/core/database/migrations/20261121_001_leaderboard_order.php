<?php

declare (strict_types=1);
return static function (PDO $db) {
    $indexes = $db->query('SHOW INDEX FROM profiles')->fetchAll(PDO::FETCH_ASSOC);
    $columns = [];
    foreach ($indexes as $index) {
        $columns[$index['Key_name']][(int) $index['Seq_in_index']] = $index['Column_name'];
    }
    $changes = [];
    foreach (['stars', 'creator_points'] as $metric) {
        $name = 'idx_profiles_' . $metric;
        if (($columns[$name][3] ?? null) === 'game_version') {
            continue;
        }
        if (isset($columns[$name])) {
            $changes[] = "DROP INDEX {$name}";
        }
        $changes[] = "ADD INDEX {$name} ({$metric} DESC, account_id ASC, game_version)";
    }
    if ($changes !== []) {
        \mucho7_domain_schema_exec($db, 'ALTER TABLE profiles ' . implode(', ', $changes));
    }
};
