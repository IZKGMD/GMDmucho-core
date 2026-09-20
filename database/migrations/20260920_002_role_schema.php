<?php

declare(strict_types=1);

return [
    <<<'SQL'
SET @has_role_column := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'accounts'
      AND column_name = 'role'
)
SQL,

    <<<'SQL'
SET @role_copy_sql := IF(
    @has_role_column = 1,
    'UPDATE accounts a
     LEFT JOIN roles r
       ON r.code = CASE
            WHEN a.role = ''helper'' THEN ''moderator''
            ELSE a.role
          END
     SET a.role_id = COALESCE(r.id, 1)',
    'SELECT 1'
)
SQL,

    <<<'SQL'
PREPARE role_copy_stmt FROM @role_copy_sql
SQL,

    <<<'SQL'
EXECUTE role_copy_stmt
SQL,

    <<<'SQL'
DEALLOCATE PREPARE role_copy_stmt
SQL,

    <<<'SQL'
SET @drop_role_sql := IF(
    @has_role_column = 1,
    'ALTER TABLE accounts DROP COLUMN role',
    'SELECT 1'
)
SQL,

    <<<'SQL'
PREPARE drop_role_stmt FROM @drop_role_sql
SQL,

    <<<'SQL'
EXECUTE drop_role_stmt
SQL,

    <<<'SQL'
DEALLOCATE PREPARE drop_role_stmt
SQL,
];
