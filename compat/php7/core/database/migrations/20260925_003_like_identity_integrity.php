<?php

declare (strict_types=1);
/*
 * Allow anonymous likes to be unique per source IP while preserving the
 * authenticated account uniqueness contract.
 */
return static function (PDO $db) {
    $hasIndex = static function (PDO $db, string $index): bool {
        $q = $db->prepare('SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = \'likes\'
               AND index_name = :index');
        $q->execute(['index' => $index]);
        return (int) $q->fetchColumn() > 0;
    };
    if (!$hasIndex($db, 'uq_like_item_user_ip')) {
        try {
            \mucho7_domain_schema_exec($db, 'ALTER TABLE likes
                 DROP INDEX uq_like_item_user');
        } catch (Throwable $__mcCaught) {
            // Older installations may already have the corrected index.
        }
        \mucho7_domain_schema_exec($db, 'ALTER TABLE likes
             ADD UNIQUE KEY uq_like_item_user_ip
             (item_id, type, account_id, ip)');
    }
};
