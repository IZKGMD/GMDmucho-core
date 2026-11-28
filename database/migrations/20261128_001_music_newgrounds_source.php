<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $columns = $db->query("SHOW COLUMNS FROM songs LIKE 'newgrounds_id'")->fetchAll();
    if ($columns === []) {
        $db->exec('ALTER TABLE songs ADD COLUMN newgrounds_id INT UNSIGNED NULL');
    }

    $indexes = $db->query("SHOW INDEX FROM songs WHERE Key_name = 'songs_newgrounds_id'")->fetchAll();
    if ($indexes === []) {
        $db->exec('CREATE INDEX songs_newgrounds_id ON songs (newgrounds_id, is_verified, id)');
    }
};
