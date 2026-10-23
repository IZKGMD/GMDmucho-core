<?php

declare (strict_types=1);
/*
 * Normalize MuchoCore game roles to the Geometry Dash moderator model:
 * user, moderator, elder_moderator, owner.
 *
 * Existing "admin" game roles are migrated to elder_moderator.
 * Existing "helper" game roles are migrated to moderator.
 */
return static function (PDO $db) {
    $db->exec("\n        INSERT INTO roles (code, name, priority)\n        VALUES\n            ('elder_moderator', 'Elder Moderator', 75)\n        ON DUPLICATE KEY UPDATE\n            name = VALUES(name),\n            priority = VALUES(priority)\n    ");
    $db->exec("\n        UPDATE accounts a\n        LEFT JOIN roles existing_role\n            ON existing_role.id = a.role_id\n        INNER JOIN roles canonical_role\n            ON canonical_role.code = CASE\n                WHEN LOWER(TRIM(COALESCE(a.role, ''))) IN\n                    ('admin', 'elder', 'developer')\n                    THEN 'elder_moderator'\n                WHEN LOWER(TRIM(COALESCE(a.role, ''))) IN\n                    ('helper', 'mod')\n                    THEN 'moderator'\n                WHEN LOWER(TRIM(COALESCE(a.role, ''))) = 'player'\n                    THEN 'user'\n                WHEN LOWER(TRIM(COALESCE(a.role, ''))) = 'user'\n                    THEN 'user'\n                ELSE existing_role.code\n            END\n        SET a.role_id = canonical_role.id\n        WHERE a.role IS NOT NULL\n          AND (\n              existing_role.id IS NULL\n              OR existing_role.code IS NULL\n              OR existing_role.code = 'user'\n          )\n    ");
    $db->exec("\n        UPDATE accounts a\n        INNER JOIN roles old_role\n            ON old_role.id = a.role_id\n        INNER JOIN roles new_role\n            ON new_role.code = CASE old_role.code\n                WHEN 'admin' THEN 'elder_moderator'\n                WHEN 'helper' THEN 'moderator'\n                ELSE old_role.code\n            END\n        SET a.role_id = new_role.id\n        WHERE old_role.code IN ('admin', 'helper')\n    ");
    $db->exec("\n        UPDATE roles\n        SET\n            code = 'elder_moderator',\n            name = 'Elder Moderator',\n            priority = 75\n        WHERE code = 'admin'\n          AND NOT EXISTS (\n              SELECT 1\n              FROM roles r2\n              WHERE r2.code = 'elder_moderator'\n          )\n    ");
    $db->exec("\n        DELETE FROM roles\n        WHERE code = 'helper'\n    ");
    $db->exec("\n        DELETE old_role\n        FROM roles old_role\n        INNER JOIN roles canonical\n            ON canonical.code = 'elder_moderator'\n            AND canonical.id <> old_role.id\n        WHERE old_role.code = 'admin'\n    ");
};
