<?php
declare(strict_types=1);

// Portable between MySQL 5.7, MySQL 8 and MariaDB.
return static function (PDO $db): void {
    $stmt = $db->query("SHOW COLUMNS FROM levels LIKE 'unlisted2'");
    if ($stmt->fetch(PDO::FETCH_ASSOC) === false) {
        $db->exec(
            'ALTER TABLE levels
             ADD COLUMN unlisted2 TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
             AFTER is_unlisted'
        );
    }
};
