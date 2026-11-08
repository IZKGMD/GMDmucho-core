<?php

declare(strict_types=1);

// Existing rows remain in their original GD wire representation (0).
// New rows are stored as normalized UTF-8 text (1), and serializers select
// the matching encoder. This avoids rewriting or risking existing inboxes.
return [
    'ALTER TABLE messages ADD COLUMN content_format TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER body',
];
