<?php

declare(strict_types=1);

use MuchoCore\Database\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        $column = $pdo->query(
            "SHOW COLUMNS FROM accounts LIKE 'role'"
        )->fetch();

        if (!$column) {
            return;
        }

        /*
         * 014_moderation.php introduced a duplicate legacy role column.
         * Move its value back into the canonical roles/role_id schema
         * before removing the duplicate column.
         *
         * "helper" existed in the old admin UI but has no canonical role,
         * so it is mapped to moderator.
         */
        $pdo->exec(
            "UPDATE accounts a
             JOIN roles r
               ON r.code = CASE
                    WHEN a.`role` = 'helper' THEN 'moderator'
                    ELSE a.`role`
                  END
             SET a.role_id = r.id"
        );

        $userRole = $pdo->query(
            "SELECT id
             FROM roles
             WHERE code = 'user'
             LIMIT 1"
        )->fetchColumn();

        if ($userRole === false) {
            throw new RuntimeException(
                'Canonical user role is missing.'
            );
        }

        /*
         * Any legacy/unknown role must not leave a stale value behind.
         * accounts.role_id has a foreign key to roles, so normalize it.
         */
        $stmt = $pdo->prepare(
            "UPDATE accounts
             SET role_id = :user_role
             WHERE role_id IS NULL
                OR role_id NOT IN (
                    SELECT id FROM roles
                )"
        );

        $stmt->execute([
            'user_role' => (int)$userRole
        ]);

        $pdo->exec(
            "ALTER TABLE accounts
             DROP COLUMN `role`"
        );
    }

    public function down(PDO $pdo): void
    {
        /*
         * The old duplicate column is intentionally not recreated.
         * The canonical roles/role_id schema is the supported model.
         */
    }
};
